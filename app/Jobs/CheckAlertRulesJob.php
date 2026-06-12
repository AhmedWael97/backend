<?php

namespace App\Jobs;

use App\Models\AlertRule;
use App\Models\Domain;
use App\Services\ClickHouseService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class CheckAlertRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $domainId)
    {
    }

    public function handle(ClickHouseService $clickhouse, NotificationService $notifier): void
    {
        $domain = Domain::with('user')->findOrFail($this->domainId);
        $rules = AlertRule::where('domain_id', $this->domainId)
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            $this->checkRule($rule, $domain, $clickhouse, $notifier);
        }
    }

    private function checkRule(AlertRule $rule, Domain $domain, ClickHouseService $clickhouse, NotificationService $notifier): void
    {
        $threshold = $rule->threshold;
        $breached = false;
        $data = [];

        switch ($rule->type) {
            case 'traffic_drop':
                $pct = (float) ($threshold['percent'] ?? 30);
                $todayCount = $this->eventCount($clickhouse, $this->domainId, now()->subDay(), now());
                $yestCount = $this->eventCount($clickhouse, $this->domainId, now()->subDays(2), now()->subDay());
                if ($yestCount > 0) {
                    $drop = ($yestCount - $todayCount) / $yestCount * 100;
                    $breached = $drop >= $pct;
                    $data = ['title' => "Traffic dropped {$drop}%", 'body' => "Traffic dropped by {$drop}% vs yesterday."];
                }
                break;

            case 'error_spike':
                $pct = (float) ($threshold['percent'] ?? 5);
                $errors = (int) ($clickhouse->select("SELECT count() AS c FROM ux_events WHERE domain_id = {$this->domainId} AND type = 'js_error' AND created_at >= '" . now()->subHour()->format('Y-m-d H:i:s') . "'")[0]['c'] ?? 0);
                $total = max(1, (int) ($clickhouse->select("SELECT count() AS c FROM events WHERE domain_id = {$this->domainId} AND ts >= '" . now()->subHour()->format('Y-m-d H:i:s') . "'")[0]['c'] ?? 1));
                $rate = $errors / $total * 100;
                $breached = $rate >= $pct;
                $data = ['title' => "Error spike detected", 'body' => "JS error rate reached {$rate}% in the last hour."];
                break;

            case 'quota_warning':
                $pct = (float) ($threshold['percent'] ?? 80);
                $plan = $domain->user->subscription?->plan;
                $maxPerDay = $plan?->getLimit('max_events_per_day_per_domain', 10000) ?? 10000;
                $quotaKey = "quota:{$domain->script_token}:events:" . now()->format('Y-m-d');
                $used = (int) Redis::get($quotaKey);
                $usedPct = $maxPerDay > 0 ? ($used / $maxPerDay * 100) : 0;
                $breached = $usedPct >= $pct;
                $data = ['title' => "Quota {$usedPct}% used", 'body' => "You've used {$usedPct}% of your daily event quota for {$domain->domain}.", 'domain' => $domain->domain, 'percent' => (int) $usedPct];
                break;

            case 'conversion_drop':
                // Completed orders over the last 24h vs the prior 24h. Wrapped in
                // try/catch since the conversions table may not be migrated yet.
                $pct = (float) ($threshold['percent'] ?? 30);
                try {
                    $today = (int) ($clickhouse->select("SELECT uniqExact(order_id) AS c FROM conversions WHERE domain_id = {$this->domainId} AND ts >= '" . now()->subDay()->format('Y-m-d H:i:s') . "'")[0]['c'] ?? 0);
                    $prev = (int) ($clickhouse->select("SELECT uniqExact(order_id) AS c FROM conversions WHERE domain_id = {$this->domainId} AND ts >= '" . now()->subDays(2)->format('Y-m-d H:i:s') . "' AND ts < '" . now()->subDay()->format('Y-m-d H:i:s') . "'")[0]['c'] ?? 0);
                    if ($prev > 0) {
                        $drop = round(($prev - $today) / $prev * 100, 1);
                        $breached = $drop >= $pct;
                        $data = ['title' => "Orders dropped {$drop}%", 'body' => "Completed orders dropped {$drop}% vs the prior day ({$today} vs {$prev})."];
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
                break;

            case 'traffic_anomaly':
                // Compare the last completed hour against a baseline of the same
                // hour-of-day over the previous 14 days (z-score). Catches sudden
                // drops/spikes without the false positives of a naive hourly mean.
                $sensitivity = (float) ($threshold['sensitivity'] ?? 2.5);
                $lastHourStart = now()->subHour()->startOfHour();
                $lastHourEnd = (clone $lastHourStart)->addHour();
                $targetHour = (int) $lastHourStart->format('G');
                $current = $this->eventCount($clickhouse, $this->domainId, $lastHourStart, $lastHourEnd);

                $baselineRows = $clickhouse->select("
                    SELECT count() AS c
                    FROM events
                    WHERE domain_id = {$this->domainId}
                      AND toHour(ts) = {$targetHour}
                      AND ts >= '" . now()->subDays(14)->format('Y-m-d H:i:s') . "'
                      AND toDate(ts) < toDate(now())
                    GROUP BY toDate(ts)
                ");
                $counts = array_map(fn($r) => (float) $r['c'], $baselineRows);
                if (count($counts) >= 5) {
                    $mean = array_sum($counts) / count($counts);
                    $variance = 0.0;
                    foreach ($counts as $c) {
                        $variance += ($c - $mean) ** 2;
                    }
                    $std = sqrt($variance / count($counts));
                    if ($std > 0) {
                        $z = ($current - $mean) / $std;
                        if (abs($z) >= $sensitivity) {
                            $breached = true;
                            $dir = $z < 0 ? 'below' : 'above';
                            $data = [
                                'title' => 'Traffic anomaly detected',
                                'body' => "Last hour had {$current} events — " . round(abs($z), 1) . "σ {$dir} the typical " . round($mean) . " for this hour.",
                            ];
                        }
                    }
                }
                break;

            case 'score_drop':
                // Handled implicitly by UxScore trends — skip for now
                break;
        }

        if ($breached) {
            // Cooldown so a rule that stays breached doesn't notify on every run.
            $cooldownKey = "eye:alert:cooldown:{$rule->id}";
            if (!Redis::get($cooldownKey)) {
                if (in_array($rule->channel, ['slack', 'discord'], true) && $rule->webhook_url) {
                    $this->sendWebhook($rule->channel, (string) $rule->webhook_url, $domain, $data);
                } else {
                    $notifier->send($domain->user, 'alert', $data, $this->domainId);
                }
                Redis::setex($cooldownKey, 6 * 3600, '1'); // 6h
            }
        }
    }

    /**
     * Post an alert to a Slack or Discord incoming webhook. Both accept a simple
     * JSON body — Slack uses "text", Discord uses "content".
     */
    private function sendWebhook(string $channel, string $url, Domain $domain, array $data): void
    {
        $title = $data['title'] ?? 'EYE alert';
        $body = $data['body'] ?? '';

        if ($channel === 'discord') {
            $payload = ['content' => "**[{$domain->domain}] {$title}**\n{$body}"];
        } else {
            $payload = ['text' => "*[{$domain->domain}] {$title}*\n{$body}"];
        }

        try {
            \Illuminate\Support\Facades\Http::timeout(8)->asJson()->post($url, $payload);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function eventCount(ClickHouseService $clickhouse, int $domainId, \Carbon\Carbon $from, \Carbon\Carbon $to): int
    {
        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        return (int) ($clickhouse->select(
            "SELECT count() AS c FROM events WHERE domain_id = {$domainId} AND ts >= '{$fromStr}' AND ts < '{$toStr}'"
        )[0]['c'] ?? 0);
    }
}
