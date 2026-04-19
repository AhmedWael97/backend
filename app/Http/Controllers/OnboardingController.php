<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    private const STEPS = [
        'domain_added',
        'script_installed',
        'first_event_received',
        'funnel_created',
    ];

    public function show(Request $request): JsonResponse
    {
        $onboarding = $request->user()->onboarding ?? [];

        $status = [];
        foreach (self::STEPS as $step) {
            $status[$step] = (bool) ($onboarding[$step] ?? false);
        }

        return $this->success($status);
    }

    public function markStep(Request $request, string $step): JsonResponse
    {
        if (!in_array($step, self::STEPS, true)) {
            abort(404, "Unknown onboarding step: {$step}");
        }

        $user = $request->user();
        $onboarding = $user->onboarding ?? [];
        $onboarding[$step] = true;
        $user->update(['onboarding' => $onboarding]);

        return $this->success([
            'message' => "Step '{$step}' marked complete.",
            'data' => $onboarding,
        ]);
    }
}
