<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeDomainJob;
use App\Models\Domain;
use App\Models\Subscription;
use Illuminate\Console\Command;

class ScheduleAnalysisCommand extends Command
{
    protected $signature = 'eye:analyze';
    protected $description = 'Dispatch AnalyzeDomainJob for all domains due for AI analysis.';

    public function handle(): void
    {
        Domain::with('user.subscription.plan')
            ->where('active', true)
            ->whereNotNull('script_verified_at')
            ->chunk(100, function ($domains) {
                foreach ($domains as $domain) {
                    $plan = $domain->user?->subscription?->plan;
                    $interval = (int) ($plan?->getLimit('ai_analysis_interval_hours', 24) ?? 24);

                    $lastReport = \App\Models\AiReport::where('domain_id', $domain->id)
                        ->latest('generated_at')
                        ->value('generated_at');

                    if (!$lastReport || now()->diffInHours($lastReport) >= $interval) {
                        AnalyzeDomainJob::dispatch($domain->id)->onQueue('ai');
                        $this->line("Queued analysis for domain #{$domain->id}");
                    }
                }
            });
    }
}
