<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AdSpend;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ad-spend CRUD + CSV import for campaign ROAS.
 *
 * Spend rows are matched to campaign rows by (source, campaign) in
 * CampaignsController to compute ROAS = revenue / spend and CPA = spend / orders.
 *
 *   GET    /api/v1/analytics/{domainId}/ad-spend          — list (filtered by date)
 *   POST   /api/v1/analytics/{domainId}/ad-spend          — upsert one spend row
 *   POST   /api/v1/analytics/{domainId}/ad-spend/import   — bulk CSV import
 *   DELETE /api/v1/analytics/{domainId}/ad-spend/{id}     — delete one row
 */
class AdSpendController extends Controller
{
    public function index(Request $request, int $domainId): JsonResponse
    {
        $this->authorizeDomain($request, $domainId);

        $start = $request->query('start', now()->subDays(30)->toDateString());
        $end = $request->query('end', now()->toDateString());

        $rows = AdSpend::where('domain_id', $domainId)
            ->whereBetween('date', [$start, $end])
            ->orderByDesc('date')
            ->orderBy('source')
            ->get();

        $total = (float) $rows->sum('spend');

        return $this->success([
            'rows' => $rows,
            'total_spend' => round($total, 2),
        ]);
    }

    public function store(Request $request, int $domainId): JsonResponse
    {
        $this->authorizeDomain($request, $domainId);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'source' => ['required', 'string', 'max:120'],
            'campaign' => ['nullable', 'string', 'max:200'],
            'medium' => ['nullable', 'string', 'max:60'],
            'spend' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'clicks' => ['nullable', 'integer', 'min:0'],
            'impressions' => ['nullable', 'integer', 'min:0'],
        ]);

        $row = $this->upsertRow($domainId, $data);

        return $this->success($row, 201);
    }

    public function import(Request $request, int $domainId): JsonResponse
    {
        $this->authorizeDomain($request, $domainId);

        $request->validate(['csv' => ['required', 'string']]);
        $csv = (string) $request->input('csv');

        // Expected header (case-insensitive, order-independent):
        //   date, source, campaign, spend, currency, clicks, impressions
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (empty($lines) || count($lines) < 2) {
            return $this->error('CSV must have a header row and at least one data row.', 422);
        }

        $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $idx = array_flip($header);
        foreach (['date', 'source', 'spend'] as $req) {
            if (!isset($idx[$req])) {
                return $this->error("CSV is missing the required '{$req}' column.", 422);
            }
        }

        $imported = 0;
        $skipped = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $get = fn(string $key) => isset($idx[$key], $cols[$idx[$key]]) ? trim((string) $cols[$idx[$key]]) : null;

            $date = $get('date');
            $source = $get('source');
            $spend = $get('spend');

            // Skip malformed rows rather than aborting the whole import.
            if (!$date || !$source || $spend === null || !is_numeric($spend) || strtotime($date) === false) {
                $skipped++;
                continue;
            }

            $this->upsertRow($domainId, [
                'date' => date('Y-m-d', strtotime($date)),
                'source' => $source,
                'campaign' => $get('campaign') ?: '(none)',
                'medium' => $get('medium'),
                'spend' => (float) $spend,
                'currency' => $get('currency') ?: 'USD',
                'clicks' => is_numeric($get('clicks')) ? (int) $get('clicks') : null,
                'impressions' => is_numeric($get('impressions')) ? (int) $get('impressions') : null,
            ]);
            $imported++;
        }

        return $this->success(['imported' => $imported, 'skipped' => $skipped]);
    }

    public function destroy(Request $request, int $domainId, int $id): JsonResponse
    {
        $this->authorizeDomain($request, $domainId);

        $deleted = AdSpend::where('domain_id', $domainId)->where('id', $id)->delete();

        return $deleted
            ? $this->success(['deleted' => true])
            : $this->error('Spend row not found.', 404);
    }

    /**
     * Upsert on the (domain_id, date, source, campaign) unique key.
     */
    private function upsertRow(int $domainId, array $data): AdSpend
    {
        return AdSpend::updateOrCreate(
            [
                'domain_id' => $domainId,
                'date' => $data['date'],
                'source' => $data['source'],
                'campaign' => $data['campaign'] ?? '(none)',
            ],
            [
                'medium' => $data['medium'] ?? null,
                'spend' => $data['spend'],
                'currency' => strtoupper($data['currency'] ?? 'USD'),
                'clicks' => $data['clicks'] ?? null,
                'impressions' => $data['impressions'] ?? null,
            ]
        );
    }

    /**
     * Ensure the domain belongs to the user (super admins see all). Mirrors
     * the check in CampaignsController.
     */
    private function authorizeDomain(Request $request, int $domainId): Domain
    {
        $user = $request->user();
        return Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();
    }
}
