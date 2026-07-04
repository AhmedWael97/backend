<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PublicPlanController extends Controller
{
    /** GET /plans — public plan prices for the marketing pricing page. */
    public function index(): JsonResponse
    {
        $plans = Plan::where('is_public', true)
            ->orderBy('price_monthly')
            ->get(['slug', 'name', 'name_ar', 'price_monthly', 'price_yearly'])
            ->map(fn ($p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'name_ar' => $p->name_ar,
                'price_monthly' => (float) $p->price_monthly,
                'price_yearly' => (float) $p->price_yearly,
            ]);

        return $this->success($plans);
    }
}
