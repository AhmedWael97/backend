<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Invite & earn" — GET only; referral rows are created at registration
 * (RegisterController / GoogleController) and rewarded by the scheduled
 * eye:process-referral-rewards command.
 */
class ReferralController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->referral_code) {
            $user->update(['referral_code' => User::generateReferralCode()]);
        }

        $referrals = Referral::where('referrer_user_id', $user->id)
            ->with('referred:id,name,email')
            ->latest()
            ->get()
            ->map(fn (Referral $r) => [
                'name' => $r->referred?->name,
                // Masked — this list is visible to the referrer, not an admin.
                'email' => $this->maskEmail($r->referred?->email ?? ''),
                'status' => $r->status,
                'created_at' => $r->created_at,
                'rewarded_at' => $r->rewarded_at,
            ]);

        return $this->success([
            'code' => $user->referral_code,
            'share_url' => rtrim((string) config('app.frontend_url'), '/') . "/auth/register?ref={$user->referral_code}",
            'reward_days' => 14,
            'referrals' => $referrals,
            'rewarded_count' => $referrals->where('status', 'rewarded')->count(),
        ]);
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2) . str_repeat('*', max(1, mb_strlen($local) - 2)) . '@' . $domain;
    }
}
