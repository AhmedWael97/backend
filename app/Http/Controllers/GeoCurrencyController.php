<?php

namespace App\Http\Controllers;

use App\Services\GeoIpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/geo/currency  (public)
 *
 * Resolves the display/billing currency from the visitor's IP country.
 * Egyptian visitors get EGP at the configured rate; everyone else gets USD.
 * Base plan prices are stored in USD — the frontend multiplies by `rate`.
 * Paymob always charges EGP regardless (see PaymobController).
 */
class GeoCurrencyController extends Controller
{
    public function __invoke(Request $request, GeoIpService $geo): JsonResponse
    {
        $country = '';
        try {
            $country = strtoupper($geo->lookup($request->ip())['country'] ?? '');
        } catch (\Throwable) {
            // Geo lookup is best-effort; fall through to USD.
        }

        $rate = (float) config('services.currency.egp_rate', 60);

        if ($country === 'EG') {
            return $this->success([
                'country' => $country,
                'currency' => 'EGP',
                'rate' => $rate,
                'symbol' => 'E£',
            ]);
        }

        return $this->success([
            'country' => $country,
            'currency' => 'USD',
            'rate' => 1.0,
            'symbol' => '$',
        ]);
    }
}
