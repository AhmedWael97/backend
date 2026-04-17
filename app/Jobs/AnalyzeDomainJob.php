<?php

namespace App\Jobs;

use App\Models\AiReport;
use App\Models\AiSuggestion;
use App\Models\AudienceSegment;
use App\Models\Domain;
use App\Services\AnthropicService;
use App\Services\ClickHouseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class AnalyzeDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(public readonly int $domainId)
    {
    }

    public function handle(ClickHouseService $clickhouse, AnthropicService $claude): void
    {
        $domain = Domain::with('user.subscription.plan')->findOrFail($this->domainId);
        $plan = $domain->user->subscription?->plan;

        // Check monthly analysis quota
        $quotaKey = "quota:{$this->domainId}:analysis:" . now()->format('Y-m');
        $maxRuns = $plan?->getLimit('max_analysis_runs_per_domain_per_month', 5) ?? 5;

        if ($maxRuns !== -1 && (int) Redis::get($quotaKey) >= $maxRuns) {
            return; // Quota exceeded — skip silently
        }

        $domainId = $this->domainId;
        $from = now()->subDays(30)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');

        // Fetch aggregated data from ClickHouse
        $topPages = $clickhouse->select("
            SELECT url, count() AS views
            FROM events
            WHERE domain_id = {$domainId} AND ts >= '{$from}' AND ts < '{$to}'
              AND type = 'pageview'
            GROUP BY url ORDER BY views DESC LIMIT 10
        ");

        $countries = $clickhouse->select("
            SELECT country, count() AS sessions
            FROM sessions
            WHERE domain_id = {$domainId}
              AND started_at >= '{$from}' AND started_at < '{$to}'
            GROUP BY country ORDER BY sessions DESC LIMIT 10
        ");

        $devices = $clickhouse->select("
            SELECT device_type, count() AS count
            FROM events
            WHERE domain_id = {$domainId} AND ts >= '{$from}' AND ts < '{$to}'
            GROUP BY device_type
        ");

        $sessionStats = $clickhouse->select("
            SELECT
                avg(duration_seconds) AS avg_duration,
                avg(page_count)       AS avg_pages
            FROM sessions
            WHERE domain_id = {$domainId}
              AND started_at >= '{$from}' AND started_at < '{$to}'
        ");

        $uxCounts = $clickhouse->select("
            SELECT type, count() AS count
            FROM ux_events
            WHERE domain_id = {$domainId}
              AND created_at >= '{$from}' AND created_at < '{$to}'
            GROUP BY type
        ");

        $dataCtx = json_encode([
            'top_pages' => $topPages,
            'countries' => $countries,
            'devices' => $devices,
            'session_stats' => $sessionStats[0] ?? [],
            'ux_counts' => $uxCounts,
        ], JSON_PRETTY_PRINT);

        $systemPrompt = <<<'PROMPT'
You are a marketing analytics AI. Analyze the provided website data and return a strict JSON object with this exact schema:
{
  "segments": [
    {
      "name": "string",
      "description": "string (2-3 sentences)",
      "size_percent": number (0-100),
      "traits": ["string", ...],
      "color": "hex color"
    }
  ],
  "suggestions": [
    {
      "text": "string (actionable suggestion)",
      "category": "audience|marketing|ux|conversion",
      "priority": "high|medium|low"
    }
  ],
  "summary": "string (3 sentences max)"
}
Return ONLY valid JSON, no markdown, no explanation.
PROMPT;

        $userMessage = "Website analytics data for the last 30 days:\n\n{$dataCtx}";

        $result = $claude->complete($systemPrompt, $userMessage);

        if (empty($result)) {
            return;
        }

        // Persist report
        AiReport::create([
            'domain_id' => $this->domainId,
            'type' => 'full',
            'content' => $result,
            'generated_at' => now(),
        ]);

        // Persist segments
        AudienceSegment::where('domain_id', $this->domainId)->delete();
        foreach ($result['segments'] ?? [] as $seg) {
            AudienceSegment::create([
                'domain_id' => $this->domainId,
                'name' => $seg['name'] ?? 'Segment',
                'description' => $seg['description'] ?? '',
                'rules' => ['traits' => $seg['traits'] ?? []],
                'visitor_count' => (int) round(($seg['size_percent'] ?? 0) / 100 * 1000),
                'color' => $seg['color'] ?? '#6366f1',
            ]);
        }

        // Persist suggestions
        AiSuggestion::where('domain_id', $this->domainId)->where('is_dismissed', false)->delete();
        foreach ($result['suggestions'] ?? [] as $sug) {
            AiSuggestion::create([
                'domain_id' => $this->domainId,
                'text' => $sug['text'] ?? '',
                'category' => $sug['category'] ?? 'marketing',
                'priority' => $sug['priority'] ?? 'medium',
                'is_dismissed' => false,
            ]);
        }

        // Increment monthly quota
        Redis::incr($quotaKey);
        Redis::expire($quotaKey, 60 * 60 * 24 * 32); // ~1 month TTL

        // Chain downstream jobs
        ComputeUxScoreJob::dispatch($this->domainId)->onQueue('ai');
        CheckAlertRulesJob::dispatch($this->domainId)->onQueue('ai');
    }
}
