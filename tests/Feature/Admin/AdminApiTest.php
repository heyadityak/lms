<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Module;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\UserAdminAudit;
use App\Support\Keycloak\KeycloakAdminClient;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeKeycloakAdminClient;
use Tests\Feature\Leave\LeaveTestHelpers;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** Phase 15 happy paths: UC-H16, UC-B2, UC-B5, UC-U2. The full matrix is QA phase 17. */
final class AdminApiTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    private FakeKeycloakAdminClient $keycloakAdmin;

    private User $dave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
        $this->dave = User::factory()->admin()->create(['name' => 'Dave Admin']);
        $this->keycloakAdmin = new FakeKeycloakAdminClient;
        $this->app->instance(KeycloakAdminClient::class, $this->keycloakAdmin);
    }

    public function test_hr_admin_sets_the_working_week_without_rewriting_stored_days(): void
    {
        $created = $this->apply($this->alice)->assertCreated();
        $id = $created->json('data.id');
        $days = LeaveRequest::query()->where('public_id', $id)->value('days');

        $this->spec($this->api($this->carol)->getJson('/api/v1/settings/working-week'), '/settings/working-week')
            ->assertOk()
            ->assertJsonPath('data.overridden', false)
            ->assertJsonPath('data.weekend_days', config('lms.weekend_days'));

        $this->spec($this->api($this->carol)->patchJson('/api/v1/settings/working-week', [
            'weekend_days' => [5, 6],
        ]), '/settings/working-week', 'patch')->assertOk()
            ->assertJsonPath('data.weekend_days', [5, 6])
            ->assertJsonPath('data.overridden', true);

        $this->assertEquals($days, LeaveRequest::query()->where('public_id', $id)->value('days'));
    }

    public function test_invalid_weekend_days_are_rejected(): void
    {
        $this->problem(
            $this->api($this->carol)->patchJson('/api/v1/settings/working-week', [
                'weekend_days' => [1, 2, 3, 4, 5, 6, 7],
            ]),
            422,
            'VALIDATION_FAILED',
            '/settings/working-week',
            'patch',
        );
        $this->assertDatabaseMissing('settings', ['key' => 'work.weekend_days']);
    }

    public function test_null_weekend_days_reset_to_the_env_default(): void
    {
        $this->api($this->carol)->patchJson('/api/v1/settings/working-week', [
            'weekend_days' => [5, 6],
        ])->assertOk();

        $this->spec($this->api($this->carol)->patchJson('/api/v1/settings/working-week', [
            'weekend_days' => null,
        ]), '/settings/working-week', 'patch')->assertOk()
            ->assertJsonPath('data.overridden', false)
            ->assertJsonPath('data.weekend_days', config('lms.weekend_days'));

        $this->assertDatabaseMissing('settings', ['key' => 'work.weekend_days']);
    }

    public function test_invalid_brand_colour_is_rejected_and_hr_admin_cannot_patch_branding(): void
    {
        $this->problem(
            $this->asAdmin()->patchJson('/api/v1/settings/branding', ['primary_color' => '#gggggg']),
            422,
            'VALIDATION_FAILED',
            '/settings/branding',
            'patch',
        );

        $this->problem(
            $this->api($this->carol)->patchJson('/api/v1/settings/branding', ['primary_color' => '#112233']),
            403,
            'FORBIDDEN',
            '/settings/branding',
            'patch',
        );
        $this->assertDatabaseMissing('settings', ['key' => 'brand.primary_color']);
    }

    public function test_admin_cannot_enable_an_unlicensed_module(): void
    {
        $this->problem(
            $this->asAdmin()->patchJson('/api/v1/modules/multi_level_approval', ['enabled' => true]),
            422,
            'MODULE_NOT_LICENSED',
            '/modules/{moduleKey}',
            'patch',
        );

        $this->spec($this->asAdmin()->patchJson('/api/v1/modules/reports', [
            'enabled' => false,
        ]), '/modules/{moduleKey}', 'patch')->assertOk()
            ->assertJsonPath('data.key', 'reports')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.licensed', true)
            ->assertJsonPath('data.locked', false);

        $this->app->forgetScopedInstances();
        $this->assertFalse(app(Modules::class)->enabled(Module::Reports));
    }

    public function test_admin_creates_a_user_and_writes_an_audit_row(): void
    {
        $created = $this->spec($this->asAdmin()->postJson('/api/v1/admin/users', [
            'username' => 'dave.k',
            'email' => 'dave.k@acme.example',
            'first_name' => 'Dave',
            'last_name' => 'Kumar',
            'role' => 'employee',
        ]), '/admin/users', 'post')->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.username', 'dave.k')
            ->assertJsonPath('data.email', 'dave.k@acme.example')
            ->assertJsonPath('data.first_name', 'Dave')
            ->assertJsonPath('data.last_name', 'Kumar')
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.enabled', true);

        $user = User::query()->where('email', 'dave.k@acme.example')->first();
        $this->assertNotNull($user);
        $this->assertSame($created->json('data.id'), $user->public_id);
        $this->assertNotNull($user->keycloak_sub);
        $this->assertArrayNotHasKey('credentials', $this->keycloakAdmin->users[$user->keycloak_sub]);
        $this->assertSame(
            ['UPDATE_PASSWORD', 'VERIFY_EMAIL'],
            $this->keycloakAdmin->actionEmails[0]['actions'],
        );
        $this->assertTrue(UserAdminAudit::query()->where([
            'actor_id' => $this->dave->id,
            'target_user_id' => $user->id,
            'action' => 'create',
            'outcome' => 'success',
        ])->exists());
    }

    public function test_idp_unavailable_creates_no_local_user(): void
    {
        $this->keycloakAdmin->unavailable = true;

        $this->problem(
            $this->asAdmin()->postJson('/api/v1/admin/users', [
                'username' => 'erin.p',
                'email' => 'erin.p@acme.example',
                'first_name' => 'Erin',
                'last_name' => 'Patel',
            ]),
            503,
            'IDP_UNAVAILABLE',
            '/admin/users',
            'post',
        );

        $this->assertDatabaseMissing('users', ['email' => 'erin.p@acme.example']);
        $this->assertTrue(UserAdminAudit::query()->where([
            'actor_id' => $this->dave->id,
            'action' => 'create',
            'outcome' => 'failed',
            'error_code' => 'IDP_UNAVAILABLE',
        ])->whereNull('target_user_id')->exists());
    }

    private function asAdmin(): static
    {
        return $this->withToken($this->jwt(
            ['sub' => $this->dave->keycloak_sub, 'email' => $this->dave->email, 'name' => $this->dave->name],
            [$this->dave->role->value, 'admin'],
        ));
    }

    private function spec(TestResponse $response, string $path, string $method = 'get'): TestResponse
    {
        $this->assertMatchesSpec($response, $path, $method);

        return $response;
    }

    private function problem(TestResponse $response, int $status, string $code, string $path, string $method = 'get'): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertMatchesSpec($response, $path, $method);
    }
}
