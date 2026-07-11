<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Notification;
use App\Models\ToolUsageLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Connects the free-tools lead signal to an actual nudge: a logged-in user
 * who repeatedly runs a free tool (speed/SEO/sitemap checker) against a host
 * they haven't connected to EYE is engaged but missing the point of the
 * product — full automatic tracking instead of a one-off manual check.
 * In-app notification only (not in NotificationService's mail map), so this
 * doesn't add another email to an already-multi-touch lifecycle.
 */
class SuggestConnectCheckedDomainsCommand extends Command
{
    protected $signature = 'eye:suggest-connect-checked-domains';

    protected $description = 'Nudge logged-in users who repeatedly free-tool-check a domain they have not connected.';

    public function handle(NotificationService $notifications): int
    {
        $rows = ToolUsageLog::query()
            ->whereNotNull('user_id')
            ->whereNotNull('checked_host')
            ->where('created_at', '>=', now()->subDays(14))
            ->select('user_id', 'checked_host', DB::raw('count(*) as c'))
            ->groupBy('user_id', 'checked_host')
            ->having('c', '>=', 2)
            ->get();

        $sent = 0;
        foreach ($rows as $row) {
            $user = User::find($row->user_id);
            if (!$user) {
                continue;
            }

            $host = $row->checked_host;
            $orgId = $user->organization()?->id;
            $alreadyConnected = Domain::where('domain', $host)
                ->where(function ($q) use ($user, $orgId) {
                    $q->where('user_id', $user->id);
                    if ($orgId) {
                        $q->orWhere('organization_id', $orgId);
                    }
                })
                ->exists();
            if ($alreadyConnected) {
                continue;
            }

            // At most one of these nudges per user per 30 days, regardless of host.
            $recentlyNudged = Notification::where('user_id', $user->id)
                ->where('type', 'tool_domain_suggestion')
                ->where('created_at', '>=', now()->subDays(30))
                ->exists();
            if ($recentlyNudged) {
                continue;
            }

            $notifications->send($user, 'tool_domain_suggestion', [
                'title' => "Add {$host} to EYE?",
                'body' => "You've checked {$host} {$row->c} times with our free tools recently. Connect it to get automatic heatmaps, session replay, and AI insights instead of a one-off check.",
                'action_url' => '/settings/domains',
            ]);
            $sent++;
        }

        $this->info("Domain-connect suggestions sent: {$sent}");

        return self::SUCCESS;
    }
}
