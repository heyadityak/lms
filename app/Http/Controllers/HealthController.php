<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class HealthController
{
    /** Liveness: process is up. Touches no backing service. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: Postgres reachable + migrated, Redis reachable when any driver uses it. */
    public function ready(): JsonResponse
    {
        $checks = ['db' => $this->db()];
        if ($this->usesRedis()) {
            $checks['redis'] = $this->redis();
        }

        $ready = ! array_diff($checks, ['ok']);

        return response()->json(
            ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    private function db(): string
    {
        try {
            DB::select('select 1');

            return Schema::hasTable('migrations') ? 'ok' : 'not_migrated';
        } catch (Throwable $e) {
            Log::warning('readyz.db_failed', ['error' => $e::class]);

            return 'unreachable';
        }
    }

    private function redis(): string
    {
        try {
            Redis::connection()->ping();

            return 'ok';
        } catch (Throwable $e) {
            Log::warning('readyz.redis_failed', ['error' => $e::class]);

            return 'unreachable';
        }
    }

    private function usesRedis(): bool
    {
        return in_array('redis', [
            config('session.driver'),
            config('cache.stores.'.config('cache.default').'.driver'),
            config('queue.connections.'.config('queue.default').'.driver'),
        ], true);
    }
}
