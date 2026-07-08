<?php

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Models\CompanyEnrichment;
use App\Models\Domain;
use App\Models\Lead;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mini-CRM of prospects. The highest-value, fully-compliant source is "warm
 * leads": companies that already visited the user's sites (B2B enrichment).
 *
 *   GET    /api/v1/leads               — list (filter status/source/q)
 *   POST   /api/v1/leads               — add one
 *   POST   /api/v1/leads/import        — CSV import
 *   POST   /api/v1/leads/warm          — pull companies that visited my sites
 *   PUT    /api/v1/leads/{id}          — update status/notes/contact
 *   DELETE /api/v1/leads/{id}
 */
class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Lead::where('user_id', $request->user()->id);
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($src = $request->query('source')) {
            $q->where('source', $src);
        }
        if ($term = $request->query('q')) {
            $q->where(function ($w) use ($term) {
                $w->where('company', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")->orWhere('website', 'ilike', "%{$term}%");
            });
        }
        return $this->success($q->orderByDesc('score')->orderByDesc('created_at')->limit(500)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'domain_id' => ['nullable', 'integer'],
        ]);
        $data['user_id'] = $request->user()->id;
        $data['source'] = 'manual';
        return $this->success(Lead::create($data), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $lead = Lead::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate([
            'status' => ['sometimes', 'in:new,contacted,replied,won,lost'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'score' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);
        $lead->update($data);
        return $this->success($lead);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Lead::where('user_id', $request->user()->id)->findOrFail($id)->delete();
        return $this->success(['deleted' => true]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['csv' => ['required', 'string']]);
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $request->input('csv')));
        if (count($lines) < 2) {
            return $this->error('CSV needs a header row and at least one data row.', 422);
        }
        $idx = array_flip(array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines))));
        $userId = $request->user()->id;
        $imported = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $get = fn(string $k) => isset($idx[$k], $cols[$idx[$k]]) ? trim((string) $cols[$idx[$k]]) : null;
            Lead::create([
                'user_id' => $userId,
                'company' => $get('company'),
                'website' => $get('website'),
                'contact_name' => $get('contact_name') ?? $get('name'),
                'email' => $get('email'),
                'source' => 'import',
                'status' => 'new',
            ]);
            $imported++;
        }
        return $this->success(['imported' => $imported]);
    }

    /**
     * Warm leads: companies that visited the user's domains (joining ClickHouse
     * visitor ip_hash → company_enrichments). Fully compliant — they came to us.
     */
    public function warm(Request $request, ClickHouseService $ch): JsonResponse
    {
        $user = $request->user();
        // Use centralised access (superadmin→all, owner→own, org member→granted)
        // so multi-tenant users get warm leads for every site they can see.
        $domainIds = Domain::accessibleBy($user)->pluck('id')->all();

        if (empty($domainIds)) {
            return $this->success(['created' => 0, 'leads' => []]);
        }

        $hashes = [];
        try {
            $inList = implode(',', array_map('intval', $domainIds));
            $rows = $ch->select("
                SELECT DISTINCT ip_hash FROM events
                WHERE domain_id IN ({$inList}) AND ip_hash != '' AND ts >= '" . now()->subDays(90)->format('Y-m-d H:i:s') . "'
                LIMIT 5000
            ");
            $hashes = array_values(array_filter(array_map(fn($r) => (string) ($r['ip_hash'] ?? ''), $rows)));
        } catch (\Throwable $e) {
            report($e);
        }
        if (empty($hashes)) {
            return $this->success(['created' => 0, 'leads' => []]);
        }

        $companies = CompanyEnrichment::whereIn('ip_hash', $hashes)
            ->whereNotNull('company_name')
            ->where('company_name', '!=', '')
            ->get()
            ->unique('company_domain');

        $created = 0;
        $out = [];
        foreach ($companies as $c) {
            // Skip if we already have a lead for this company/domain.
            $exists = Lead::where('user_id', $user->id)
                ->where(function ($w) use ($c) {
                    $w->where('website', $c->company_domain)->orWhere('company', $c->company_name);
                })->exists();
            if ($exists) {
                continue;
            }
            $lead = Lead::create([
                'user_id' => $user->id,
                'company' => $c->company_name,
                'website' => $c->company_domain,
                'source' => 'visitor',
                'status' => 'new',
                'score' => 70, // warm — they visited us
                'notes' => trim(($c->industry ? "Industry: {$c->industry}. " : '') . ($c->employee_range ? "Size: {$c->employee_range}. " : '') . ($c->country ? "Country: {$c->country}." : '')),
            ]);
            $created++;
            $out[] = $lead;
        }

        return $this->success(['created' => $created, 'leads' => $out]);
    }
}
