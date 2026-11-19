<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\JwksCache;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_valid_token_provisions_user_on_first_call(): void
    {
        $this->withToken($this->jwt())->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'alice@example.test')
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.is_admin', false);

        $this->assertDatabaseHas('users', ['keycloak_sub' => 'sub-alice', 'role' => 'employee', 'name' => 'Alice Employee', 'is_admin' => false]);
    }

    public function test_token_with_lms_web_azp_and_no_matching_aud_is_accepted(): void
    {
        $this->withToken($this->jwt(['aud' => 'account', 'azp' => 'lms-web']))->getJson('/api/v1/me')->assertOk();
    }

    public function test_roles_are_resynced_on_every_call_highest_base_role_wins_and_admin_is_a_flag(): void
    {
        User::factory()->create(['keycloak_sub' => 'sub-alice', 'email' => 'alice@example.test', 'role' => 'manager', 'is_admin' => true]);

        $this->withToken($this->jwt(roles: ['employee', 'hr_admin', 'manager', 'admin']))->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.role', 'hr_admin')->assertJsonPath('data.is_admin', true);

        // Keycloak demoted the user: the cache follows.
        $this->withToken($this->jwt(roles: ['employee']))->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.role', 'employee')->assertJsonPath('data.is_admin', false);

        $this->assertSame(1, User::query()->count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidClaims(): array
    {
        return [
            'expired beyond leeway' => [['exp' => time() - 120, 'iat' => time() - 600]],
            'not yet valid' => [['nbf' => time() + 120]],
            'no exp' => [['exp' => null]],
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
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_expired_within_leeway_is_accepted(): void
    {
        $this->withToken($this->jwt(['exp' => time() - 10]))->getJson('/api/v1/me')->assertOk();
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->withToken($this->jwt(key: self::newKey()))->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_alg_none_is_rejected(): void
    {
        $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $this->jwt())[1]), true);

        $this->withToken($this->forged(['alg' => 'none', 'typ' => 'JWT', 'kid' => $this->kid], $claims))
            ->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_hs256_signed_with_the_public_key_is_rejected(): void
    {
        $publicPem = openssl_pkey_get_details($this->privateKey)['key'];
        $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $this->jwt())[1]), true);

        $this->withToken(JWT::encode($claims, $publicPem, 'HS256', $this->kid))
            ->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_encryption_key_never_verifies_a_signature(): void
    {
        $this->withToken($this->jwt(kid: 'enc-kid'))->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_unknown_kid_triggers_exactly_one_refetch_then_is_rate_limited(): void
    {
        $this->withToken($this->jwt(kid: 'bogus-1'))->getJson('/api/v1/me')->assertUnauthorized();
        Http::assertSentCount(2); // initial fill + one forced refetch

        $this->withToken($this->jwt(kid: 'bogus-2'))->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($this->jwt(kid: 'bogus-3'))->getJson('/api/v1/me')->assertUnauthorized();
        Http::assertSentCount(2); // refetch lock still held: Keycloak is not hammered
    }

    public function test_rotated_key_is_picked_up_by_the_refetch(): void
    {
        $this->withToken($this->jwt())->getJson('/api/v1/me')->assertOk(); // JWKS now cached

        $rotated = self::newKey();
        $this->publishedKeys[] = $this->jwk($rotated, 'rotated-kid');

        $this->withToken($this->jwt(key: $rotated, kid: 'rotated-kid'))->getJson('/api/v1/me')->assertOk();
        Http::assertSentCount(2);
        $this->assertArrayHasKey('rotated-kid', cache()->get(JwksCache::CACHE_KEY));
    }

    public function test_missing_token_is_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        Http::assertNothingSent();
    }

    public function test_token_without_lms_base_role_is_403(): void
    {
        $this->withToken($this->jwt(roles: ['offline_access']))->getJson('/api/v1/me')->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_only_token_is_403(): void
    {
        $this->withToken($this->jwt(roles: ['admin']))->getJson('/api/v1/me')->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_deactivated_user_is_401(): void
    {
        User::factory()->create(['keycloak_sub' => 'sub-alice', 'email' => 'alice@example.test', 'is_active' => false]);

        $this->withToken($this->jwt())->getJson('/api/v1/me')->assertUnauthorized();
    }

    // First API call = first login (D-43): the linking rules from the removed web callback, now on the guard path.
    public function test_first_call_links_precreated_user_by_verified_email(): void
    {
        $user = User::factory()->create(['keycloak_sub' => null, 'email' => 'alice@example.test']);

        $this->withToken($this->jwt())->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->public_id);

        $this->assertSame('sub-alice', $user->fresh()->keycloak_sub);
        $this->assertSame(1, User::query()->count());
    }

    public function test_first_call_relinks_stale_sub_by_verified_email(): void
    {
        $user = User::factory()->create(['keycloak_sub' => 'sub-old', 'email' => 'alice@example.test']);

        $this->withToken($this->jwt())->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->public_id);
        $this->assertSame('sub-alice', $user->fresh()->keycloak_sub);
    }

    public function test_unverified_email_never_links_an_existing_user(): void
    {
        User::factory()->create(['keycloak_sub' => null, 'email' => 'alice@example.test']);

        $this->withToken($this->jwt(['email_verified' => false]))->getJson('/api/v1/me')->assertForbidden();
        $this->assertNull(User::query()->sole()->keycloak_sub);
    }

    // `api` module off gates third-party clients only; the BFF (`azp` = KEYCLOAK_CLIENT_ID) keeps working (D-43 g).
    public function test_api_module_off_third_party_token_is_404(): void
    {
        config(['modules.licensed' => ['holidays']]);

        $this->withToken($this->jwt(['azp' => 'lms-api-cli']))->getJson('/api/v1/me')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_api_module_off_bff_token_passes(): void
    {
        config(['modules.licensed' => ['holidays']]);

        $this->withToken($this->jwt(['azp' => 'lms-web']))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'alice@example.test');
        // The bypass is limited to /api/v1 (guarded routes): docs stay 404 even for a BFF token.
        $this->withToken($this->jwt(['azp' => 'lms-web']))->get('/api/docs')->assertNotFound();
    }

    public function test_api_module_off_forged_bff_token_is_401(): void
    {
        config(['modules.licensed' => ['holidays']]);
        $forged = $this->jwt(['azp' => 'lms-web'], key: self::newKey()); // right kid, wrong signing key

        $this->withToken($forged)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->withToken($this->forged(['alg' => 'none'], ['azp' => 'lms-web']))->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_role_and_admin_middleware_and_guard_is_request_scoped(): void
    {
        Route::middleware(['api', 'auth:keycloak-jwt', 'role:hr_admin,manager'])->get('/api/_test/managers', fn () => ['ok' => true]);
        Route::middleware(['api', 'auth:keycloak-jwt', 'admin'])->get('/api/_test/admin', fn () => ['ok' => true]);

        $alice = $this->jwt();
        $dave = $this->jwt(['sub' => 'sub-dave', 'email' => 'dave@example.test'], ['employee', 'admin']);
        $bob = $this->jwt(['sub' => 'sub-bob', 'email' => 'bob@example.test'], ['manager', 'employee']);

        // Same application instance across requests (as an Octane worker): no user carried over.
        $this->withToken($bob)->getJson('/api/_test/managers')->assertOk();
        $this->withToken($alice)->getJson('/api/_test/managers')->assertForbidden();
        $this->withToken($alice)->getJson('/api/_test/admin')->assertForbidden();
        $this->withToken($dave)->getJson('/api/_test/admin')->assertOk();
        $this->withToken($bob)->getJson('/api/_test/admin')->assertForbidden();
        $this->withToken($bob)->getJson('/api/v1/me')->assertJsonPath('data.email', 'bob@example.test');
    }
}
