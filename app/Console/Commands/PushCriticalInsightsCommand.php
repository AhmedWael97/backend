<?php

namespace App\Console\Commands;

use App\Models\AlertRule;
use App\Models\Domain;
use App\Services\InsightEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Push InsightEngine's critical findings to whatever Slack/Discord webhook the
 * domain already has configured for alert rules — reuses that channel instead
 * of adding a separate notification setting. Same 6h-style cooldown pattern as
 * CheckAlertRulesJob so a standing problem doesn't repeat every run.
 */
class PushCriticalInsightsCommand extends Command
{
    protected $signature = 'eye:push-critical-insights';
    protected $description = 'Push critical InsightEngine findings to configured Slack/Discord webhooks.';

    private const COOLDOWN_SECONDS = 6 * 3600;

    public function handle(InsightEngine $engine): void
    {
        $webhooksByDomain = AlertRule::where('is_active', true)
            ->whereIn('channel', ['slack', 'discord'])
            ->whereNotNull('webhook_url')
            ->get()
            ->groupBy('domain_id')
            ->map(fn ($rules) => $rules->unique(fn ($r) => $r->channel . '|' . $r->webhook_url));

        if ($webhooksByDomain->isEmpty()) {
            $this->line('No domains have a Slack/Discord webhook configured.');

            return;
        }

        $domains = Domain::whereIn('id', $webhooksByDomain->keys())->get()->keyBy('id');
        $pushed = 0;

        foreach ($webhooksByDomain as $domainId => $webhooks) {
            $domain = $domains->get($domainId);
            if (!$domain) {
                continue;
            }

            foreach ($engine->overview((int) $domainId) as $finding) {
                if ($finding['severity'] !== 'critical') {
                    continue;
                }

                $cooldownKey = "eye:insight:cooldown:{$domainId}:{$finding['kind']}";
                if (Redis::exists($cooldownKey)) {
                    continue;
                }

                foreach ($webhooks as $rule) {
                    $this->sendWebhook($rule->channel, (string) $rule->webhook_url, $domain->domain, $finding);
                }
                Redis::setex($cooldownKey, self::COOLDOWN_SECONDS, '1');
                $pushed++;
            }
        }

        $this->line("Pushed {$pushed} critical finding(s).");
    }

    private function sendWebhook(string $channel, string $url, string $domainName, array $finding): void
    {
        $payload = $channel === 'discord'
            ? ['content' => "**[{$domainName}] {$finding['title']}**\n{$finding['detail']}\n→ {$finding['action']}"]
            : ['text' => "*[{$domainName}] {$finding['title']}*\n{$finding['detail']}\n→ {$finding['action']}"];

        try {
            Http::timeout(8)->asJson()->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('Insight webhook push failed', ['msg' => $e->getMessage()]);
        }
    }
}
