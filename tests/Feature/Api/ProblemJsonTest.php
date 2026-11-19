<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exceptions\IdpConflict;
use App\Exceptions\IdpUnavailable;
use App\Exceptions\InvalidTransition;
use App\Exceptions\LeaveRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** RFC 9457 shape per status (API_GUIDELINES §4); each response is also checked against the spec's response component. */
final class ProblemJsonTest extends TestCase
{
    use RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/_test')->group(function (): void {
            Route::post('/validate', fn (Request $r) => $r->validate(['days' => 'required|numeric']));
            Route::get('/forbidden', fn () => throw new AuthorizationException('You cannot approve your own leave request.'));
            Route::get('/missing', fn () => throw new ModelNotFoundException);
            Route::get('/transition', fn () => throw new InvalidTransition('Request is no longer pending (current status approved).'));
            Route::get('/overlap', fn () => throw new LeaveRuleViolation('LEAVE_OVERLAP', 'You already have a request in this range.', 'start_date'));
            Route::get('/idp-conflict', fn () => throw new IdpConflict);
            Route::get('/idp-down', fn () => throw new IdpUnavailable);
            Route::get('/boom', fn () => throw new RuntimeException('secret internals'));
        });
    }

    private function assertProblem(TestResponse $response, int $status, string $code): TestResponse
    {
        return $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', $status)
            ->assertJsonPath('code', $code)
            ->assertJsonPath('type', 'urn:lms:problem:'.strtolower(str_replace('_', '-', $code)))
            ->assertJsonStructure(['type', 'title', 'status', 'instance', 'code', 'request_id']);
    }

    public function test_401_unauthenticated_without_token(): void
    {
        $response = $this->getJson('/api/v1/me');

        $this->assertProblem($response, 401, 'UNAUTHENTICATED')
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertJsonPath('instance', '/api/v1/me');
        $this->assertMatchesSpec($response, '/me');
    }

    public function test_401_with_a_bad_token_carries_invalid_token_challenge(): void
    {
        $response = $this->withToken('not-a-jwt')->getJson('/api/v1/me');

        $this->assertProblem($response, 401, 'UNAUTHENTICATED')->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        $this->assertMatchesSpec($response, '/me');
    }

    public function test_403_forbidden(): void
    {
        $response = $this->getJson('/api/_test/forbidden');

        $this->assertProblem($response, 403, 'FORBIDDEN')->assertJsonPath('detail', 'You cannot approve your own leave request.');
        $this->assertMatchesSpec($response, '/leave-requests', 'post');
    }

    public function test_404_unknown_route_and_missing_model_even_without_json_accept(): void
    {
        $this->assertProblem($this->get('/api/nope'), 404, 'NOT_FOUND');
        $this->assertProblem($this->get('/api'), 404, 'NOT_FOUND');
        $response = $this->get('/api/_test/missing');

        $this->assertProblem($response, 404, 'NOT_FOUND');
        $this->assertMatchesSpec($response, '/me');
    }

    public function test_404_when_api_module_is_off_before_authentication(): void
    {
        config(['modules.licensed' => ['holidays']]);

        foreach (['/api/v1/me', '/api/docs', '/api/openapi.yaml'] as $uri) {
            $response = $this->get($uri);
            $this->assertProblem($response, 404, 'NOT_FOUND');
        }
        $this->assertMatchesSpec($this->getJson('/api/v1/me'), '/me');
    }

    public function test_405_has_allow_header(): void
    {
        $this->assertProblem($this->postJson('/api/v1/me'), 405, 'BAD_REQUEST')->assertHeader('Allow', 'GET, HEAD');
    }

    public function test_409_invalid_transition(): void
    {
        $response = $this->getJson('/api/_test/transition', ['X-Request-Id' => 'req-12345678']);

        $this->assertProblem($response, 409, 'INVALID_TRANSITION')->assertExactJson([
            'type' => 'urn:lms:problem:invalid-transition',
            'title' => 'Conflict',
            'status' => 409,
            'detail' => 'Request is no longer pending (current status approved).',
            'instance' => '/api/_test/transition',
            'code' => 'INVALID_TRANSITION',
            'request_id' => 'req-12345678',
        ]);
        $this->assertMatchesSpec($response, '/leave-requests', 'post');
    }

    public function test_409_idp_conflict(): void
    {
        $response = $this->getJson('/api/_test/idp-conflict');

        $this->assertProblem($response, 409, 'IDP_CONFLICT');
        $this->assertMatchesSpec($response, '/admin/users', 'post');
    }

    public function test_422_validation_has_errors_map(): void
    {
        $response = $this->postJson('/api/_test/validate', []);

        $this->assertProblem($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['detail', 'errors' => ['days']]);
        $this->assertMatchesSpec($response, '/leave-requests', 'post');
    }

    public function test_422_leave_rule_violation_carries_its_code_and_field(): void
    {
        $response = $this->getJson('/api/_test/overlap');

        $this->assertProblem($response, 422, 'LEAVE_OVERLAP')
            ->assertJsonPath('errors.start_date.0', 'You already have a request in this range.');
        $this->assertMatchesSpec($response, '/leave-requests', 'post');
    }

    public function test_500_never_leaks_internals_even_with_app_debug(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/api/_test/boom');

        $this->assertProblem($response, 500, 'INTERNAL_ERROR')->assertJsonMissingPath('detail')->assertJsonMissingPath('trace');
        $this->assertStringNotContainsString('secret internals', (string) $response->getContent());
    }

    public function test_503_idp_unavailable_has_retry_after(): void
    {
        $response = $this->getJson('/api/_test/idp-down');

        $this->assertProblem($response, 503, 'IDP_UNAVAILABLE')->assertHeader('Retry-After', '30');
        $this->assertMatchesSpec($response, '/admin/users', 'get');
    }

    public function test_api_only_no_html_pages_and_no_login_redirect(): void
    {
        // D-43: the old Blade pages are gone and any non-API path is a problem+json 404, never HTML.
        foreach (['/', '/leave-requests', '/approvals', '/holidays', '/login', '/auth/callback'] as $uri) {
            $this->assertProblem($this->get($uri), 404, 'NOT_FOUND');
        }
        // No Accept header: still 401 problem, not a redirect to a (non-existent) login route.
        $this->assertProblem($this->get('/api/v1/me'), 401, 'UNAUTHENTICATED');
    }
}
