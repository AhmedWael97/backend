<?php

namespace App\Http\Controllers;

use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class EmailController extends Controller
{
    /** Signed unsubscribe URL for a given email address. */
    public static function unsubscribeUrl(string $email): string
    {
        return URL::signedRoute('email.unsubscribe', ['email' => $email]);
    }

    /** GET /email/unsubscribe?email=…&signature=…  (public, signed) */
    public function unsubscribe(Request $request)
    {
        if (!$request->hasValidSignature()) {
            return response('Invalid or expired unsubscribe link.', 403);
        }
        $email = (string) $request->query('email');
        $user = User::where('email', $email)->first();
        if ($user) {
            EmailSuppression::firstOrCreate(
                ['user_id' => $user->id, 'email' => $email],
                ['reason' => 'unsubscribe']
            );
        }

        return response(
            '<div style="font-family:Arial;max-width:480px;margin:64px auto;text-align:center;color:#20242b">'
            . '<h2>You\'re unsubscribed</h2>'
            . '<p style="color:#6b7280">You won\'t receive marketing or onboarding emails from EYE anymore. '
            . 'Important account emails (like password resets) may still be sent.</p></div>',
            200
        )->header('Content-Type', 'text/html');
    }
}
