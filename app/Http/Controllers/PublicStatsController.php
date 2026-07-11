<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\User;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET /public/stats — real, honest platform numbers for the landing page's
 * social-proof counter. Rounded down to a floor (never inflated) and cached
 * so the landing page never triggers a live ClickHouse scan per visitor.
 */
class PublicStatsController extends Controller
{
    public function index(ClickHouseService $clickhouse): JsonResponse
    {
        $stats = Cache::remember('public:stats', 900, function () use ($clickhouse) {
            $events = 0;
            $visitors = 0;
            try {
                $rows = $clickhouse->select('SELECT count() AS c, uniq(visitor_id) AS v FROM events');
                $events = (int) ($rows[0]['c'] ?? 0);
                $visitors = (int) ($rows[0]['v'] ?? 0);
            } catch (\Throwable $e) {
                report($e);
            }

            return [
                'visitors' => $this->floorRound($visitors),
                'events' => $this->floorRound($events),
                'domains' => $this->floorRound(Domain::count()),
                'users' => $this->floorRound(User::where('role', 'user')->count()),
            ];
        });

        return $this->success($stats);
    }

    /** Round DOWN to a clean floor (never overstate: 53,250 -> 53,000, 3,803,391 -> 3,800,000). */
    private function floorRound(int $n): int
    {
        if ($n < 100) {
            return $n;
        }
        $magnitude = $n >= 1_000_000 ? 100_000 : ($n >= 10_000 ? 1_000 : 100);

        return intdiv($n, $magnitude) * $magnitude;
    }
}
