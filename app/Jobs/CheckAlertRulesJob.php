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

            case 'score_drop':
                // Handled implicitly by UxScore trends — skip for now
                break;
        }

        if ($breached) {
            $notifier->send($domain->user, 'alert', $data, $this->domainId);
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
