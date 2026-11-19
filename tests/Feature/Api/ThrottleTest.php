<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Auth\JwksCache;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\FakeKeycloak;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** `throttle:api` on /api/v1 (Redis limiter, API_GUIDELINES §8) and the JWKS-down 503. */
final class ThrottleTest extends TestCase
{
    use FakeKeycloak, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeKeycloak();
    }

    public function test_api_responses_carry_rate_limit_headers(): void
    {
        $response = $this->withToken($this->jwt())->getJson('/api/v1/me')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60');

        $this->assertIsNumeric($response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_429_rate_limited_with_retry_after_and_reset(): void
    {
        // Fresh bucket per run: the Redis counter outlives the test process.
        $bucket = 'throttle-test-'.Str::uuid();
        RateLimiter::for('api', fn (): Limit => Limit::perMinute(1)->by($bucket));
        $token = $this->jwt();

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $response = $this->withToken($token)->getJson('/api/v1/me');

        $response->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'RATE_LIMITED')
            ->assertJsonPath('type', 'urn:lms:problem:rate-limited')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertGreaterThan(time(), (int) $response->headers->get('X-RateLimit-Reset'));
        $this->assertMatchesSpec($response, '/me');
    }

    public function test_api_limiter_is_keyed_by_user_id_falling_back_to_ip(): void
    {
        $request = Request::create('/api/v1/me', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $this->assertSame('203.0.113.9', RateLimiter::limiter('api')($request)->key);

        $user = new User;
        $user->id = 42;
        $request->setUserResolver(fn (): User => $user);
        $limit = RateLimiter::limiter('api')($request);
        $this->assertSame(42, $limit->key);
        $this->assertSame(60, $limit->maxAttempts);
    }

    public function test_jwks_unreachable_without_cached_copy_is_503(): void
    {
        Http::swap(new Factory); // drop fakeKeycloak()'s JWKS stub
        Http::fake([JwksCache::url() => Http::response('down', 502)]);

        $this->withToken($this->jwt())->getJson('/api/v1/me')
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
    }
}
