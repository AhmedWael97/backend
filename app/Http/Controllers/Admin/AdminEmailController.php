<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EmailController;
use App\Mail\BrandedEmail;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Super-admin email campaigns: send a branded HTML campaign to a user segment.
 * Every mail is wrapped in the standard branded frame (header/footer/unsubscribe)
 * and queued so Horizon paces delivery — protecting sending reputation.
 */
class AdminEmailController extends Controller
{
    /** GET /admin/email/audiences — recipient counts per segment. */
    public function audiences(): JsonResponse
    {
        return $this->success([
            'all' => $this->base()->count(),
            'no_domain' => $this->base()->whereDoesntHave('domains')->whereDoesntHave('organizationMemberships')->count(),
            'has_domain' => $this->base()->whereHas('domains')->count(),
        ]);
    }

    /** POST /admin/email/send  { subject, html, audience, test_email? } */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'html' => ['required', 'string'],
            'audience' => ['required', 'in:all,no_domain,has_domain'],
            'test_email' => ['nullable', 'email'],
        ]);

        // Test send — one email to the given address, no segment.
        if (!empty($data['test_email'])) {
            Mail::to($data['test_email'])->send($this->mail($data['subject'], $data['html'], $data['test_email']));
            return $this->success(['message' => 'Test email sent to ' . $data['test_email'], 'queued' => 0]);
        }

        $query = $this->base();
        if ($data['audience'] === 'no_domain') {
            $query->whereDoesntHave('domains')->whereDoesntHave('organizationMemberships');
        } elseif ($data['audience'] === 'has_domain') {
            $query->whereHas('domains');
        }

        $queued = 0;
        $query->select('id', 'email')->chunkById(200, function ($users) use ($data, &$queued) {
            foreach ($users as $user) {
                Mail::to($user->email)->queue($this->mail($data['subject'], $data['html'], $user->email));
                $queued++;
            }
        });

        return $this->success(['message' => "Campaign queued to {$queued} recipients.", 'queued' => $queued]);
    }

    private function base()
    {
        return User::query()
            ->where('role', 'user')
            ->whereNotIn('email', EmailSuppression::pluck('email'));
    }

    private function mail(string $subject, string $html, string $email): BrandedEmail
    {
        return new BrandedEmail($subject, [
            'preheader' => $subject,
            'rawHtml' => $html,
            'unsubUrl' => EmailController::unsubscribeUrl($email),
        ]);
    }
}
