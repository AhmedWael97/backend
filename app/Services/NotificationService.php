<?php

namespace App\Services;

use App\Events\NotificationCreatedEvent;
use App\Mail\AlertMail;
use App\Mail\ExportReadyMail;
use App\Mail\QuotaWarningMail;
use App\Mail\ScriptDetectedMail;
use App\Mail\SubscriptionChangedMail;
use App\Mail\WelcomeMail;
use App\Mail\WeeklyDigestMail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    private static array $mailMap = [
        'welcome' => WelcomeMail::class,
        'script_detected' => ScriptDetectedMail::class,
        'alert' => AlertMail::class,
        'quota_warning' => QuotaWarningMail::class,
        'export_ready' => ExportReadyMail::class,
        'subscription_changed' => SubscriptionChangedMail::class,
        'weekly_digest' => WeeklyDigestMail::class,
    ];

    public function send(User $user, string $type, array $data = [], ?int $domainId = null): void
    {
        $pref = NotificationPreference::where('user_id', $user->id)
            ->where('type', $type)
            ->first();

        $inApp = $pref ? (bool) $pref->in_app : true;
        $email = $pref ? (bool) $pref->email : true;

        if ($inApp) {
            $notification = Notification::create([
                'user_id' => $user->id,
                'domain_id' => $domainId,
                'type' => $type,
                'title' => $data['title'] ?? ucfirst($type),
                'body' => $data['body'] ?? '',
                'action_url' => $data['action_url'] ?? null,
                'channel' => $email ? 'both' : 'in_app',
            ]);

            broadcast(new NotificationCreatedEvent($user->id, $notification->toArray()))
                ->toOthers();
        }

        if ($email && isset(self::$mailMap[$type])) {
            $mailClass = self::$mailMap[$type];
            Mail::to($user->email)->queue(new $mailClass($user, $data));

            if (isset($notification)) {
                $notification->update(['email_sent_at' => now()]);
            }
        }
    }
}
