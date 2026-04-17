<?php

namespace App\Jobs;

use App\Models\CompanyEnrichment;
use App\Services\ClickHouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class EnrichCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $ipHash,
        public readonly string $rawIp,
        public readonly int $domainId,
        public readonly string $sessionId,
    ) {
    }

    public function handle(ClickHouseService $clickhouse): void
    {
        $token = config('services.ipinfo.token');
        if (!$token) {
            return;
        }

        $cacheKey = "enrich:{$this->ipHash}";

        // Attempt IPinfo lookup
        $response = Http::withToken($token)
            ->timeout(5)
            ->get("https://ipinfo.io/{$this->rawIp}/json");

        if ($response->failed()) {
            return;
        }

        $raw = $response->json();

        $enrichment = CompanyEnrichment::updateOrCreate(
            ['ip_hash' => $this->ipHash],
            [
                'company_name' => $raw['company']['name'] ?? ($raw['org'] ?? ''),
                'company_domain' => $raw['company']['domain'] ?? '',
                'industry' => $raw['company']['type'] ?? '',
                'employee_range' => '',
                'country' => $raw['country'] ?? '',
                'raw' => $raw,
                'enriched_at' => now(),
            ]
        );

        // Cache for 24 hours
        Redis::setex($cacheKey, 86400, json_encode([
            'company_name' => $enrichment->company_name,
            'company_domain' => $enrichment->company_domain,
            'industry' => $enrichment->industry,
        ]));

        // Back-fill sessions.company_name for this session
        if ($enrichment->company_name) {
            try {
                $name = addslashes($enrichment->company_name);
                $sid = addslashes($this->sessionId);
                $did = $this->domainId;
                $clickhouse->statement(
                    "ALTER TABLE sessions UPDATE company_name = '{$name}' WHERE domain_id = {$did} AND session_id = '{$sid}'"
                );
            } catch (\Throwable) {
                // Back-fill is best-effort
            }
        }
    }
}
