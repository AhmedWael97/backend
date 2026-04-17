<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];
        $latency = [];
        $status = 'ok';

        // PostgreSQL check
        try {
            $t = microtime(true);
            DB::select('SELECT 1');
            $latency['db'] = (int) ((microtime(true) - $t) * 1000);
            $checks['db'] = true;
        } catch (\Throwable) {
            $checks['db'] = false;
            $latency['db'] = null;
            $status = 'degraded';
        }

        // Redis check
        try {
            $t = microtime(true);
            Redis::ping();
            $latency['redis'] = (int) ((microtime(true) - $t) * 1000);
            $checks['redis'] = true;
        } catch (\Throwable) {
            $checks['redis'] = false;
            $latency['redis'] = null;
            $status = 'degraded';
        }

        // ClickHouse check
        try {
            $t = microtime(true);
            $ch = app(\App\Services\ClickHouseService::class);
            $ch->select('SELECT 1');
            $latency['clickhouse'] = (int) ((microtime(true) - $t) * 1000);
            $checks['clickhouse'] = true;
        } catch (\Throwable) {
            $checks['clickhouse'] = false;
            $latency['clickhouse'] = null;
            $status = 'degraded';
        }

        $httpStatus = $status === 'ok' ? 200 : 503;

        return response()->json([
            'status' => $status,
            'checks' => $checks,
            'latency_ms' => $latency,
        ], $httpStatus);
    }
}
