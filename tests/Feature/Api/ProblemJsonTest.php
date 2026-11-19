<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ProblemJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $bucket = 'problem-test-'.uniqid();
        RateLimiter::for('problem-test', fn () => Limit::perMinute(1)->by($bucket));
        Route::middleware('api')->prefix('api/_test')->group(function (): void {
            Route::post('/validate', fn (Request $r) => $r->validate(['days' => 'required|numeric']));
            Route::get('/forbidden', fn () => throw new AuthorizationException('nope'));
            Route::get('/conflict', fn () => throw new DomainException('Request is already approved.', 'invalid_transition', 409));
            Route::get('/boom', fn () => throw new RuntimeException('secret internals'));
            Route::get('/throttled', fn () => ['ok' => true])->middleware('throttle:problem-test');
        });
    }

    public function test_validation_is_422_with_errors_map(): void
    {
        $this->postJson('/api/_test/validate', [])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'urn:lms:problem:validation_failed')
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('instance', '/api/_test/validate')
            ->assertJsonStructure(['title', 'detail', 'request_id', 'errors' => ['days']]);
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_forbidden_is_403(): void
    {
        $this->getJson('/api/_test/forbidden')->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    public function test_not_found_is_404_even_without_json_accept_header(): void
    {
        $this->get('/api/nope')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'not_found');
    }

    public function test_domain_exception_carries_its_code_and_status(): void
    {
        $this->getJson('/api/_test/conflict', ['X-Request-Id' => 'req-12345678'])
            ->assertStatus(409)
            ->assertExactJson([
                'type' => 'urn:lms:problem:invalid_transition',
                'title' => 'Conflict',
                'status' => 409,
                'detail' => 'Request is already approved.',
                'instance' => '/api/_test/conflict',
                'code' => 'invalid_transition',
                'request_id' => 'req-12345678',
            ]);
    }

    public function test_throttle_is_429_with_retry_after(): void
    {
        $this->getJson('/api/_test/throttled')->assertOk()->assertHeader('X-RateLimit-Limit', '1');
        $this->getJson('/api/_test/throttled')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_unexpected_error_is_500_without_internals(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('code', 'internal_error')
            ->assertJsonMissingPath('detail');
    }
}
