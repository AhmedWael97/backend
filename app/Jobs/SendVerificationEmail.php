<?php

namespace App\Jobs;

use App\Http\Controllers\EmailController;
use App\Mail\BrandedEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Sends the "account created — please activate" verification email, OUT of the
 * request cycle. Registration only dispatches this (never sends inline), so a
 * mail failure can never fail signup. Safe to re-dispatch (skips verified users).
 */
class SendVerificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId)
    {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user || $user->hasVerifiedEmail()) {
            return;
        }

        // Signed backend link — EmailVerificationController::verify marks the user
        // verified then redirects to the frontend. Valid for 7 days.
        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addDays(7),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $ar = ($user->locale ?? 'en') === 'ar';
        $name = $user->name ?: ($ar ? 'مرحبًا' : 'there');

        Mail::to($user->email)->send(new BrandedEmail(
            $ar ? 'تم إنشاء حسابك — فعّله الآن' : 'Your EYE account is ready — activate it',
            [
                'preheader' => $ar ? 'خطوة أخيرة: فعّل بريدك لتبدأ.' : 'One last step: confirm your email to get started.',
                'heading' => $ar ? "أهلًا {$name}، تم إنشاء حسابك بنجاح 🎉" : "Welcome {$name}, your account is created 🎉",
                'lines' => $ar
                    ? [
                        'تم إنشاء حسابك في EYE بنجاح. تبقّت خطوة واحدة: تفعيل بريدك الإلكتروني.',
                        'اضغط الزر بالأسفل لتفعيل حسابك والبدء في رؤية زوّار موقعك.',
                    ]
                    : [
                        'Your EYE account was created successfully. There is just one step left: confirm your email address.',
                        'Click the button below to activate your account and start seeing who visits your website.',
                    ],
                'ctaText' => $ar ? 'فعّل حسابي' : 'Activate my account',
                'ctaUrl' => $verifyUrl,
                'replyNote' => $ar
                    ? 'لم تنشئ هذا الحساب؟ تجاهل هذه الرسالة.'
                    : "Didn't create this account? You can safely ignore this email.",
                'unsubUrl' => EmailController::unsubscribeUrl($user->email),
            ]
        ));
    }
}
