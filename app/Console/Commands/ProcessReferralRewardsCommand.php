<?php

namespace App\Console\Commands;

use App\Models\Referral;
use Illuminate\Console\Command;

/**
 * Two-sided reward: once a referred account proves it's real — verified email
 * AND at least one connected domain (same anti-abuse bar as the agency-plan
 * gate) — extend both the referrer's and the referred user's free-trial by 14
 * days. No-ops for paid subscriptions (current_period_end is null there, so
 * there's nothing to extend).
 */
class ProcessReferralRewardsCommand extends Command
{
    protected $signature = 'eye:process-referral-rewards';
    protected $description = 'Reward both sides of a referral once the referred account has real usage.';

    private const REWARD_DAYS = 14;

    public function handle(): int
    {
        $pending = Referral::where('status', 'pending')
            ->with(['referrer.activeSubscription', 'referred.activeSubscription'])
            ->whereHas('referred', function ($q) {
                $q->whereNotNull('email_verified_at')->whereHas('domains');
            })
            ->limit(200)
            ->get();

        $rewarded = 0;
        foreach ($pending as $referral) {
            $this->extendTrial($referral->referrer);
            $this->extendTrial($referral->referred);
            $referral->update(['status' => 'rewarded', 'rewarded_at' => now()]);
            $rewarded++;
        }

        $this->line("Rewarded {$rewarded} referral(s).");

        return self::SUCCESS;
    }

    private function extendTrial(?\App\Models\User $user): void
    {
        $sub = $user?->activeSubscription;
        if (!$sub || $sub->current_period_end === null) {
            return; // no trial to extend (paid plan, or none)
        }
        $sub->update(['current_period_end' => $sub->current_period_end->addDays(self::REWARD_DAYS)]);
    }
}
