<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /billing/promo/validate — live discount preview before checkout.
 * Read-only: does not redeem the code (that happens on confirmed payment,
 * in PaymobController's webhook, so an abandoned checkout never burns a use).
 */
class PromoCodeController extends Controller
{
    public function validateCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $promo = PromoCode::whereRaw('upper(code) = ?', [strtoupper($data['code'])])->first();
        if (!$promo) {
            return $this->error('Invalid promo code.', 422);
        }

        $reason = $promo->invalidReason($request->user()->id);
        if ($reason) {
            return $this->error($reason, 422);
        }

        $plan = Plan::findOrFail($data['plan_id']);
        $priceUsd = (float) ($plan->price_monthly ?? 0);
        $discountUsd = $promo->discountUsd($priceUsd);

        return $this->success([
            'code' => $promo->code,
            'discount_type' => $promo->discount_type,
            'discount_value' => (float) $promo->discount_value,
            'original_price_usd' => $priceUsd,
            'discount_usd' => $discountUsd,
            'final_price_usd' => round($priceUsd - $discountUsd, 2),
        ]);
    }
}
