<?php

namespace App\Http\Controllers\Analytics;

use App\Models\BotHit;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/analytics/{domainId}/bot-stats
 *
 * Returns bot-hit totals for a domain:
 *   { today: int, total: int }
 */
class BotStatsController extends \App\Http\Controllers\Controller
{
    public function __invoke(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $today = (int) BotHit::where('domain_id', $domain->id)
            ->where('date', now()->toDateString())
            ->value('hits');

        $total = (int) BotHit::where('domain_id', $domain->id)->sum('hits');

        return response()->json(['today' => $today, 'total' => $total]);
    }
}
