<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\Support\FakeKeycloak;
use Tests\TestCase;

final class KeycloakLoginTest extends TestCase
{
    use FakeKeycloak, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeKeycloak();
    }

    public function test_login_redirects_browser_to_public_keycloak_authorize_url_with_pkce(): void
    {
        $location = $this->get('/login')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(config('keycloak.public_url').'/realms/lms/protocol/openid-connect/auth?', $location);
        $this->assertStringContainsString('client_id=lms-web', $location);
        $this->assertStringContainsString('scope=openid+profile+email', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_first_login_provisions_user_by_keycloak_sub_and_stores_id_token(): void
    {
        $this->fakeSocialiteUser(['default-roles-lms', 'employee']);

        $this->get('/auth/callback')->assertRedirect('/')->assertSessionHas('keycloak_id_token', 'the-id-token');

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('sub-bob', $user->keycloak_sub);
        $this->assertSame('employee', $user->role);
        $this->assertFalse($user->is_admin);
        $this->assertSame('bob@example.test', $user->email);
    }

    public function test_first_login_links_precreated_user_by_verified_email(): void
    {
        $user = User::factory()->create(['keycloak_sub' => null, 'email' => 'bob@example.test']);
        $this->fakeSocialiteUser(['employee']);

        $this->get('/auth/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('sub-bob', $user->fresh()->keycloak_sub);
        $this->assertSame(1, User::query()->count());
    }

    public function test_login_relinks_stale_sub_by_verified_email(): void
    {
        $user = User::factory()->create(['keycloak_sub' => 'sub-old', 'email' => 'bob@example.test']);
        $this->fakeSocialiteUser(['employee']);

        $this->get('/auth/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('sub-bob', $user->fresh()->keycloak_sub);
    }

    public function test_unverified_email_never_links_an_existing_user(): void
    {
        User::factory()->create(['keycloak_sub' => null, 'email' => 'bob@example.test']);
        $this->fakeSocialiteUser(['employee'], $this->jwt(['sub' => 'sub-bob', 'email' => 'bob@example.test', 'email_verified' => false, 'azp' => 'lms-web']));

        $this->get('/auth/callback')->assertForbidden();
        $this->assertGuest();
        $this->assertNull(User::query()->sole()->keycloak_sub);
    }

    public function test_login_resyncs_base_role_and_admin_flag(): void
    {
        $user = User::factory()->create(['keycloak_sub' => 'sub-bob', 'email' => 'bob@example.test', 'role' => 'employee', 'is_admin' => false]);
        $this->fakeSocialiteUser(['manager', 'employee', 'admin']);

        $this->get('/auth/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame('manager', $user->role);
        $this->assertTrue($user->is_admin);
    }

    public function test_login_without_base_role_is_refused(): void
    {
        $this->fakeSocialiteUser(['offline_access']);

        $this->get('/auth/callback')->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_only_user_is_refused(): void
    {
        $this->fakeSocialiteUser(['admin']);

        $this->get('/auth/callback')->assertForbidden();
        $this->assertGuest();
    }

    public function test_deactivated_user_is_refused(): void
    {
        User::factory()->create(['keycloak_sub' => 'sub-bob', 'email' => 'bob@example.test', 'is_active' => false]);
        $this->fakeSocialiteUser(['employee']);

        $this->get('/auth/callback')->assertForbidden();
        $this->assertGuest();
    }

    public function test_unverifiable_token_is_refused(): void
    {
        $this->fakeSocialiteUser(['employee'], $this->jwt(['iss' => 'http://evil.test/realms/lms', 'azp' => 'lms-web']));

        $this->get('/auth/callback')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_logout_ends_session_and_redirects_to_keycloak_end_session_with_id_token_hint(): void
    {
        $user = User::factory()->create();

        $location = $this->actingAs($user)
            ->withSession(['keycloak_id_token' => 'the-id-token'])
            ->post('/logout')
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertGuest();
        $this->assertSame(
            config('keycloak.public_url').'/realms/lms/protocol/openid-connect/logout'
                .'?post_logout_redirect_uri='.urlencode(rtrim(config('app.url'), '/').'/')
                .'&client_id=lms-web&id_token_hint=the-id-token',
            $location,
        );
    }

    /** @param  list<string>  $roles */
    private function fakeSocialiteUser(array $roles, ?string $accessToken = null): void
    {
        $kcUser = (new SocialiteUser)
            ->setRaw(['sub' => 'sub-bob', 'email' => 'bob@example.test'])
            ->map(['id' => 'sub-bob', 'email' => 'bob@example.test', 'name' => 'Bob Manager'])
            ->setToken($accessToken ?? $this->jwt(['sub' => 'sub-bob', 'email' => 'Bob@Example.test', 'name' => 'Bob Manager', 'azp' => 'lms-web'], $roles))
            ->setAccessTokenResponseBody(['id_token' => 'the-id-token']);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($kcUser);
        Socialite::shouldReceive('driver')->with('keycloak')->andReturn($provider);
    }
}
