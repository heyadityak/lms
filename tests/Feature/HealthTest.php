<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** Needs the compose/CI Postgres (lms_test) and Redis. */
final class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthz_is_ok_without_session_cookie(): void
    {
        $this->get('/healthz')
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('X-Request-Id')
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_readyz_is_ready_when_db_and_redis_are_up(): void
    {
        $this->get('/readyz')
            ->assertOk()
            ->assertExactJson(['status' => 'ready', 'checks' => ['db' => 'ok', 'redis' => 'ok']]);
    }

    public function test_readyz_returns_503_when_db_is_unreachable(): void
    {
        config(['database.connections.broken' => array_merge(
            config('database.connections.pgsql'),
            ['host' => '127.0.0.1', 'port' => 1],
        )]);
        config(['database.default' => 'broken']);
        DB::purge('broken');

        $this->get('/readyz')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'checks' => ['db' => 'fail', 'redis' => 'ok']]);

        config(['database.default' => 'pgsql']);
    }

    public function test_readyz_returns_503_when_redis_is_unreachable(): void
    {
        config(['database.redis.default.port' => 1, 'database.redis.default.max_retries' => 0]);
        Redis::purge('default');

        $this->get('/readyz')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'checks' => ['db' => 'ok', 'redis' => 'fail']]);

        Redis::purge('default');
    }

    public function test_request_id_from_proxy_is_echoed(): void
    {
        $this->get('/healthz', ['X-Request-Id' => 'abc123def456'])
            ->assertHeader('X-Request-Id', 'abc123def456');
    }
}
