<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\CompanyEnrichment;
use App\Models\Domain;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    /**
     * GET /api/analytics/{domainId}/companies
     * Pro plan required.
     */
    public function index(Request $request, int $domainId): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        // Company enrichment is available to everyone (no plan gate).

        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));
        $industry = $request->query('industry');
        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Aggregate visiting companies from ClickHouse `sessions` (company_name is
        // backfilled by EnrichCompanyJob). The enrichment detail (domain, industry,
        // headcount) lives in the PostgreSQL company_enrichments table, so we join
        // it in PHP below — ClickHouse cannot query the Postgres table.
        $rows = $this->clickhouse->select("
            SELECT
                company_name,
                any(country)              AS country,
                uniq(visitor_id)          AS visitors,
                count()                   AS sessions,
                toString(max(started_at)) AS last_seen
            FROM sessions
            WHERE domain_id = {$domain->id}
              AND started_at >= '{$from} 00:00:00'
              AND started_at <= '{$to} 23:59:59'
              AND company_name IS NOT NULL
              AND company_name != ''
            GROUP BY company_name
            ORDER BY sessions DESC
            LIMIT 500
        ");

        $names = array_values(array_filter(array_map(
            fn ($r) => (string) ($r['company_name'] ?? ''),
            $rows
        )));

        $meta = collect();
        if (!empty($names)) {
            $meta = CompanyEnrichment::whereIn('company_name', $names)
                ->get(['company_name', 'company_domain', 'industry', 'employee_range'])
                ->keyBy('company_name');
        }

        $data = [];
        foreach ($rows as $r) {
            $name = (string) ($r['company_name'] ?? '');
            $m = $meta[$name] ?? null;
            $ind = $m->industry ?? '';
            if ($industry && $ind !== $industry) {
                continue;
            }
            $data[] = [
                'id' => $name,
                'name' => $name,
                'company_domain' => $m->company_domain ?? '',
                'industry' => $ind,
                'country' => $r['country'] ?? '',
                'employee_range' => $m->employee_range ?? '',
                'visits' => (int) ($r['sessions'] ?? 0),
                'visitors' => (int) ($r['visitors'] ?? 0),
                'last_visit' => $r['last_seen'] ?? null,
            ];
        }

        $paged = array_slice($data, $offset, $limit);

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => $paged,
            'meta' => [
                'per_page' => $limit,
                'current_page' => $page,
                'total' => count($data),
            ],
        ]);
    }

    /**
     * GET /api/analytics/{domainId}/companies/{companyDomain}
     */
    public function show(Request $request, int $domainId, string $companyDomain): JsonResponse
    {
        $user = $request->user();
        $domain = Domain::where('id', $domainId)
            ->accessibleBy($user)
            ->firstOrFail();

        // Resolve the company name from its domain (PostgreSQL), then pull that
        // company's sessions from ClickHouse (company_enrichments is not in CH).
        $companyName = CompanyEnrichment::where('company_domain', $companyDomain)
            ->value('company_name');
        if (!$companyName) {
            return $this->success([]);
        }

        $safeName = str_replace("'", '', $companyName);
        $rows = $this->clickhouse->select("
            SELECT
                visitor_id,
                session_id,
                country,
                toString(started_at) AS started_at
            FROM sessions
            WHERE domain_id = {$domain->id}
              AND company_name = '{$safeName}'
            ORDER BY started_at DESC
            LIMIT 200
        ");

        return $this->success($rows);
    }
}
