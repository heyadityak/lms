<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Liveness/readiness probes (SYSTEM_DESIGN §11, openapi `Readiness`). Never checks Keycloak, S3 or SMTP. */
final class HealthController
{
    /** Liveness: process is up. Touches no backing service. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: Postgres reachable + migrated, Redis reachable. */
    public function ready(): JsonResponse
    {
        $checks = [
            'db' => $this->check('db', fn (): bool => DB::select('select 1') !== [] && Schema::hasTable('migrations')),
            'redis' => $this->check('redis', fn (): bool => (bool) Redis::connection()->ping()),
        ];
        $ready = ! in_array('fail', $checks, true);

        return response()->json(
            ['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    /** @param callable(): bool $probe */
    private function check(string $name, callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'fail';
        } catch (Throwable $e) {
            Log::warning("readyz.{$name}_failed", ['error' => $e::class]);

            return 'fail';
        }
    }
}
