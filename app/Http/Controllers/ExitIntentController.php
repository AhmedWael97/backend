<?php

namespace App\Http\Controllers;

use App\Mail\BrandedEmail;
use App\Models\EmailSuppression;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Exit-intent popup capture (landing/pricing pages, public, no login).
 * Emails a fixed 10%-off code — no new lead-storage table; the promo code's
 * own redemption row is the attribution once someone actually checks out.
 */
class ExitIntentController extends Controller
{
    private const CODE = 'WELCOME10';

    public function capture(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        if (EmailSuppression::where('email', $data['email'])->exists()) {
            return $this->success(['message' => 'Check your inbox for the code.']);
        }

        $promo = PromoCode::firstOrCreate(
            ['code' => self::CODE],
            [
                'campaign_name' => 'Exit Intent Popup',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'max_uses' => null,
                'is_active' => true,
            ]
        );

        try {
            Mail::to($data['email'])->queue(new BrandedEmail(
                "Here's your 10% off EYE Analytics",
                [
                    'preheader' => "Use code {$promo->code} at checkout — no rush, it doesn't expire.",
                    'heading' => 'Your discount code',
                    'lines' => [
                        "Thanks for checking out EYE. Whenever you're ready, use the code below at checkout for <strong>10% off</strong> your first subscription.",
                        "<strong style=\"font-size:20px;letter-spacing:1px;\">{$promo->code}</strong>",
                    ],
                    'ctaText' => 'Start free trial',
                    'ctaUrl' => rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/') . '/en/auth/register',
                    'unsubUrl' => EmailController::unsubscribeUrl($data['email']),
                ]
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->success(['message' => 'Check your inbox for the code.']);
    }
}
