<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeKeycloak;
use Tests\TestCase;

final class KeycloakJwtTest extends TestCase
{
    use FakeKeycloak, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeKeycloak();
    }

    public function test_valid_token_creates_user_on_first_call_and_maps_role(): void
    {
        $this->withToken($this->jwt())->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'alice@example.test')
            ->assertJsonPath('data.role', 'employee')
            ->assertHeader('X-RateLimit-Limit');

        $this->assertDatabaseHas('users', ['keycloak_sub' => 'sub-alice', 'role' => 'employee', 'name' => 'Alice Employee']);
    }

    public function test_role_is_resynced_from_token_and_highest_role_wins(): void
    {
        User::factory()->create(['keycloak_sub' => 'sub-alice', 'email' => 'alice@example.test', 'role' => 'employee']);

        $this->withToken($this->jwt(['roles' => ['employee', 'hr_admin', 'manager']]))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'hr_admin');

        $this->assertSame(1, User::query()->count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidClaims(): array
    {
        return [
            'expired' => [['exp' => time() - 120, 'iat' => time() - 600]],
            'not yet valid' => [['nbf' => time() + 120]],
            'wrong issuer' => [['iss' => 'http://evil.test/realms/lms']],
            'wrong audience' => [['aud' => 'other', 'azp' => 'other-client']],
            'id token, not access token' => [['typ' => 'ID']],
        ];
    }

    /** @param  array<string, mixed>  $claims */
    #[DataProvider('invalidClaims')]
    public function test_invalid_claims_are_rejected(array $claims): void
    {
        $this->withToken($this->jwt($claims))->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'unauthenticated');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->withToken($this->jwt([], self::newKey()))->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_unknown_kid_is_rejected_after_one_jwks_refetch(): void
    {
        $this->withToken($this->jwt([], null, 'rotated-kid'))->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_missing_token_is_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_token_without_lms_role_is_403(): void
    {
        $this->withToken($this->jwt(['roles' => ['offline_access']]))->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_role_middleware_gates_by_synced_role(): void
    {
        Route::middleware(['api', 'auth:keycloak', 'role:hr_admin'])->get('/api/_test/hr-only', fn () => ['ok' => true]);

        $this->withToken($this->jwt())->getJson('/api/_test/hr-only')->assertForbidden();
        $this->app['auth']->forgetGuards(); // fresh guard per request, as under php-fpm
        $this->withToken($this->jwt(['roles' => ['hr_admin']]))->getJson('/api/_test/hr-only')->assertOk();
    }

    public function test_api_module_off_is_404(): void
    {
        config(['modules.licensed' => ['holidays']]);

        $this->withToken($this->jwt())->getJson('/api/v1/me')->assertNotFound()->assertJsonPath('code', 'not_found');
    }
}
