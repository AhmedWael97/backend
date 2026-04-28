<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Resend the email verification notification.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success(['message' => 'Email already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return $this->success(['message' => 'Verification email sent.']);
    }

    /**
     * Mark the authenticated user's email address as verified.
     * Redirects to the frontend after verification so the user
     * lands on a proper page instead of seeing raw JSON.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $locale = $request->query('locale', 'en');

        $user = \App\Models\User::findOrFail($id);

        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect("{$frontendUrl}/{$locale}/auth/verify-email?error=invalid");
        }

        if ($user->hasVerifiedEmail()) {
            return redirect("{$frontendUrl}/{$locale}/settings/domains?welcome=1&verified=already");
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return redirect("{$frontendUrl}/{$locale}/settings/domains?welcome=1&verified=1");
    }
}
