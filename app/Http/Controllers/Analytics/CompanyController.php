<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
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
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Gate behind Pro plan
        $plan = optional($request->user()->subscription?->plan);
        if (!$plan->getLimit('b2b_intelligence', false)) {
            return $this->error('Company enrichment is a Pro plan feature. Please upgrade.', 403, ['upgrade' => true]);
        }

        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->query('to', now()->format('Y-m-d'));
        $industry = $request->query('industry');
        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $industryFilter = $industry
            ? "AND industry = '" . addslashes($industry) . "'"
            : '';

        $rows = $this->clickhouse->select("
            SELECT
                company_name,
                any(company_domain) AS company_domain,
                any(industry)       AS industry,
                any(employee_range) AS employee_range,
                uniq(visitor_id)    AS visitors,
                count()             AS sessions,
                max(started_at)     AS last_seen
            FROM sessions
            INNER JOIN (
                SELECT ip_hash, company_domain, industry, employee_range
                FROM company_enrichments
            ) AS ce ON sessions.ip_hash = ce.ip_hash
            WHERE domain_id = {$domain->id}
              AND started_at >= '{$from} 00:00:00'
              AND started_at <= '{$to} 23:59:59'
              AND company_name != ''
              {$industryFilter}
            GROUP BY company_name
            ORDER BY sessions DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        return response()->json([
            'statusCode' => 200,
            'statusText' => 'success',
            'data' => $rows,
            'meta' => [
                'per_page' => $limit,
                'current_page' => $page,
            ],
        ]);
    }

    /**
     * GET /api/analytics/{domainId}/companies/{companyDomain}
     */
    public function show(Request $request, int $domainId, string $companyDomain): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $plan = optional($request->user()->subscription?->plan);
        if (!$plan->getLimit('b2b_intelligence', false)) {
            return $this->error('Upgrade to Pro.', 403, ['upgrade' => true]);
        }

        $rows = $this->clickhouse->select("
            SELECT
                visitor_id,
                session_id,
                entry_url,
                duration_seconds,
                page_count,
                country,
                device,
                started_at
            FROM sessions
            WHERE domain_id = {$domain->id}
              AND company_name = (
                  SELECT company_name FROM company_enrichments
                  WHERE company_domain = '" . addslashes($companyDomain) . "'
                  LIMIT 1
              )
            ORDER BY started_at DESC
            LIMIT 200
        ");

        return $this->success($rows);
    }
}
