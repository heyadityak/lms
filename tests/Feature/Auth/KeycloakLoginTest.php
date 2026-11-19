<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\TestCase;

final class KeycloakLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirects_browser_to_public_keycloak_authorize_url(): void
    {
        $location = $this->get('/login')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(config('keycloak.public_url').'/realms/lms/protocol/openid-connect/auth?', $location);
        $this->assertStringContainsString('client_id=lms-web', $location);
        $this->assertStringContainsString('scope=openid', $location);
    }

    public function test_first_login_creates_user_from_claims(): void
    {
        $this->fakeSocialiteUser(['employee']);
        $this->get('/auth/callback')->assertRedirect('/');

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('sub-bob', $user->keycloak_sub);
        $this->assertSame('employee', $user->role);
        $this->assertSame('bob@example.test', $user->email);
    }

    public function test_login_resyncs_role_of_existing_user(): void
    {
        $user = User::factory()->create(['keycloak_sub' => 'sub-bob', 'email' => 'bob@example.test', 'role' => 'employee']);
        $this->fakeSocialiteUser(['manager', 'employee']);

        $this->get('/auth/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('manager', $user->fresh()->role);
        $this->assertSame(1, User::query()->count());
    }

    public function test_login_without_lms_role_is_forbidden(): void
    {
        $this->fakeSocialiteUser(['offline_access']);

        $this->get('/auth/callback')->assertForbidden();
        $this->assertGuest();
    }

    public function test_logout_ends_local_session_and_redirects_to_keycloak_logout_with_id_token_hint(): void
    {
        $user = User::factory()->create();

        $location = $this->actingAs($user)
            ->withSession(['keycloak_id_token' => 'the-id-token'])
            ->post('/logout')
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertGuest();
        $this->assertStringStartsWith(config('keycloak.public_url').'/realms/lms/protocol/openid-connect/logout?', $location);
        $this->assertStringContainsString('id_token_hint=the-id-token', $location);
    }

    /** @param  list<string>  $roles */
    private function fakeSocialiteUser(array $roles): void
    {
        $kcUser = (new SocialiteUser)
            ->setRaw(['sub' => 'sub-bob', 'email' => 'Bob@Example.test', 'name' => 'Bob Manager', 'roles' => $roles])
            ->map(['id' => 'sub-bob', 'email' => 'bob@example.test', 'name' => 'Bob Manager'])
            ->setAccessTokenResponseBody(['id_token' => 'id-token']);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($kcUser);
        Socialite::shouldReceive('driver')->with('keycloak')->andReturn($provider);
    }
}
