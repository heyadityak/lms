<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Auth\KeycloakUserProvisioner;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\UserAdminAudit;
use App\Support\Keycloak\KeycloakAdminClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeKeycloakAdminClient;
use Tests\Feature\Leave\LeaveTestHelpers;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Phase 15 gaps: branding assets, working-week invalid sets, identity guardrails.
 * Happy paths that already pass in AdminApiTest are not repeated.
 */
final class AdminEdgeTest extends TestCase
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

    /** @return array<string, array{0: list<int>}> */
    public static function invalidWeekendSets(): array
    {
        return [
            'duplicate' => [[6, 6]],
            'zero' => [[0]],
            'eight' => [[8]],
            'mixed out of range' => [[1, 8]],
        ];
    }

    #[DataProvider('invalidWeekendSets')]
    public function test_invalid_weekend_sets_are_rejected(array $days): void
    {
        $this->problem(
            $this->api($this->carol)->patchJson('/api/v1/settings/working-week', ['weekend_days' => $days]),
            422,
            'VALIDATION_FAILED',
            '/settings/working-week',
            'patch',
        );
        $this->assertDatabaseMissing('settings', ['key' => 'work.weekend_days']);
    }

    public function test_uploaded_logo_is_stored_served_and_removed(): void
    {
        $disk = $this->fakeBrandDisk();
        $png = self::pngBytes();

        $uploaded = $this->spec($this->asAdmin()->put('/api/v1/settings/branding/assets/logo', [
            'file' => self::pngUpload('logo.png'),
        ]), '/settings/branding/assets/{asset}', 'put')->assertOk();
        $this->assertStringContainsString('/branding/logo?v=', (string) $uploaded->json('data.logo_url'));
        $this->assertStringNotContainsString('v=default', (string) $uploaded->json('data.logo_url'));
        $this->assertContains('brand.logo_path', $uploaded->json('data.overridden'));

        $stored = collect($disk->allFiles())->first(fn (string $path): bool => str_starts_with($path, 'logo-'));
        $this->assertIsString($stored);
        $this->assertSame($png, $disk->get($stored));
        $this->app->forgetScopedInstances();
        $this->assertSame($png, $this->get('/branding/logo')->assertOk()->streamedContent());

        $favicon = $this->spec($this->asAdmin()->put('/api/v1/settings/branding/assets/favicon', [
            'file' => self::pngUpload('favicon.png'),
        ]), '/settings/branding/assets/{asset}', 'put')->assertOk();
        $this->assertContains('brand.favicon_path', $favicon->json('data.overridden'));

        $removed = $this->spec(
            $this->asAdmin()->deleteJson('/api/v1/settings/branding/assets/logo'),
            '/settings/branding/assets/{asset}',
            'delete',
        )->assertOk();
        $this->assertStringContainsString('v=default', (string) $removed->json('data.logo_url'));
        $this->assertNotContains('brand.logo_path', $removed->json('data.overridden'));
        $this->assertFalse($disk->exists($stored));
        $this->app->forgetScopedInstances();
        $this->assertSame(
            file_get_contents(public_path('img/default-logo.png')),
            $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png')->streamedContent(),
        );
    }

    public function test_svg_branding_upload_is_rejected(): void
    {
        $disk = $this->fakeBrandDisk();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';

        $this->problem(
            $this->asAdmin()->put('/api/v1/settings/branding/assets/logo', [
                'file' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
            ]),
            415,
            'UNSUPPORTED_MEDIA_TYPE',
            '/settings/branding/assets/{asset}',
            'put',
        );
        $this->problem(
            $this->asAdmin()->put('/api/v1/settings/branding/assets/logo', [
                'file' => UploadedFile::fake()->createWithContent('logo.png', $svg),
            ]),
            415,
            'UNSUPPORTED_MEDIA_TYPE',
            '/settings/branding/assets/{asset}',
            'put',
        );

        $this->assertSame([], $disk->allFiles());
        $this->assertDatabaseMissing('settings', ['key' => 'brand.logo_path']);
    }

    public function test_hr_admin_cannot_upload_delete_or_reset_branding(): void
    {
        $disk = $this->fakeBrandDisk();

        $this->problem(
            $this->api($this->carol)->put('/api/v1/settings/branding/assets/logo', [
                'file' => self::pngUpload('logo.png'),
            ]),
            403,
            'FORBIDDEN',
            '/settings/branding/assets/{asset}',
            'put',
        );
        $this->problem(
            $this->api($this->carol)->deleteJson('/api/v1/settings/branding/assets/logo'),
            403,
            'FORBIDDEN',
            '/settings/branding/assets/{asset}',
            'delete',
        );
        $this->problem(
            $this->api($this->carol)->deleteJson('/api/v1/settings/branding'),
            403,
            'FORBIDDEN',
            '/settings/branding',
            'delete',
        );

        $this->assertSame([], $disk->allFiles());
        $this->assertDatabaseMissing('settings', ['key' => 'brand.logo_path']);
        $this->assertDatabaseMissing('settings', ['key' => 'brand.name']);
    }

    public function test_reset_branding_clears_name_and_stored_assets(): void
    {
        $disk = $this->fakeBrandDisk();
        $this->asAdmin()->patchJson('/api/v1/settings/branding', ['name' => 'Phase 17'])->assertOk();
        $this->asAdmin()->put('/api/v1/settings/branding/assets/logo', [
            'file' => self::pngUpload('logo.png'),
        ])->assertOk();
        $this->assertNotEmpty($disk->allFiles());

        $reset = $this->spec(
            $this->asAdmin()->deleteJson('/api/v1/settings/branding'),
            '/settings/branding',
            'delete',
        )->assertOk();
        $this->assertSame(config('branding.name'), $reset->json('data.name'));
        $this->assertSame([], $reset->json('data.overridden'));
        $this->assertSame([], $disk->allFiles());
        $this->assertDatabaseMissing('settings', ['key' => 'brand.name']);
        $this->assertDatabaseMissing('settings', ['key' => 'brand.logo_path']);
    }

    public function test_self_role_change_and_self_disable_are_forbidden(): void
    {
        $this->problem(
            $this->asAdmin()->putJson("/api/v1/admin/users/{$this->dave->public_id}/roles", [
                'roles' => ['employee'],
            ]),
            403,
            'SELF_MODIFICATION_FORBIDDEN',
            '/admin/users/{userId}/roles',
            'put',
        );
        $this->problem(
            $this->asAdmin()->postJson("/api/v1/admin/users/{$this->dave->public_id}/disable"),
            403,
            'SELF_MODIFICATION_FORBIDDEN',
            '/admin/users/{userId}/disable',
            'post',
        );

        $this->assertTrue($this->dave->refresh()->is_admin);
        $this->assertTrue($this->dave->is_active);
        $this->assertTrue(UserAdminAudit::query()->where([
            'actor_id' => $this->dave->id,
            'action' => 'assign_role',
            'outcome' => 'failed',
            'error_code' => 'SELF_MODIFICATION_FORBIDDEN',
        ])->exists());
        $this->assertTrue(UserAdminAudit::query()->where([
            'actor_id' => $this->dave->id,
            'action' => 'disable',
            'outcome' => 'failed',
            'error_code' => 'SELF_MODIFICATION_FORBIDDEN',
        ])->exists());
    }

    public function test_last_active_admin_cannot_be_demoted_or_disabled(): void
    {
        $frank = $this->createIdentityUser('frank.a', 'frank.a@acme.example', true);

        // Two active admins: the other one can lose admin.
        $this->spec($this->asAdmin()->putJson("/api/v1/admin/users/{$frank->public_id}/roles", [
            'roles' => ['employee'],
        ]), '/admin/users/{userId}/roles', 'put')->assertOk()
            ->assertJsonPath('data.is_admin', false);
        $this->assertFalse($frank->refresh()->is_admin);

        $this->spec($this->asAdmin()->putJson("/api/v1/admin/users/{$frank->public_id}/roles", [
            'roles' => ['employee', 'admin'],
        ]), '/admin/users/{userId}/roles', 'put')->assertOk()
            ->assertJsonPath('data.is_admin', true);

        // G2 blocks the sole admin from editing themselves, so the 409 is the other side of the
        // lock: this request is still authenticated as admin, but the caller's persisted flag was
        // already cleared. The count then sees Dave as the only active admin.
        $this->armClearedAdminFlag($frank);

        $this->problem(
            $this->tokenFor($frank, true)->putJson("/api/v1/admin/users/{$this->dave->public_id}/roles", [
                'roles' => ['employee'],
            ]),
            409,
            'LAST_ADMIN',
            '/admin/users/{userId}/roles',
            'put',
        );
        $this->problem(
            $this->tokenFor($frank, true)->postJson("/api/v1/admin/users/{$this->dave->public_id}/disable"),
            409,
            'LAST_ADMIN',
            '/admin/users/{userId}/disable',
            'post',
        );

        $this->assertTrue($this->dave->refresh()->is_admin);
        $this->assertTrue($this->dave->is_active);
        $this->assertSame(2, UserAdminAudit::query()->where([
            'outcome' => 'failed',
            'error_code' => 'LAST_ADMIN',
        ])->count());
    }

    public function test_federated_profile_edit_is_read_only(): void
    {
        $user = $this->createIdentityUser('gia.f', 'gia.f@acme.example');
        $this->keycloakAdmin->users[$user->keycloak_sub]['federationLink'] = 'ldap-1';

        $this->problem(
            $this->asAdmin()->patchJson("/api/v1/admin/users/{$user->public_id}", [
                'email' => 'gia.changed@acme.example',
            ]),
            422,
            'IDP_READ_ONLY',
            '/admin/users/{userId}',
            'patch',
        );

        $this->assertSame('gia.f@acme.example', $user->refresh()->email);
        $this->assertSame('gia.f@acme.example', $this->keycloakAdmin->users[$user->keycloak_sub]['email']);
        $this->assertTrue(UserAdminAudit::query()->where([
            'target_user_id' => $user->id,
            'action' => 'update_profile',
            'outcome' => 'failed',
            'error_code' => 'IDP_READ_ONLY',
        ])->exists());
    }

    public function test_duplicate_identity_user_is_idp_conflict(): void
    {
        $this->createIdentityUser('erin.p', 'erin.p@acme.example');

        $this->problem(
            $this->asAdmin()->postJson('/api/v1/admin/users', [
                'username' => 'erin.p',
                'email' => 'erin.p@acme.example',
                'first_name' => 'Erin',
                'last_name' => 'Patel',
            ]),
            409,
            'IDP_CONFLICT',
            '/admin/users',
            'post',
        );

        $this->assertSame(1, User::query()->where('email', 'erin.p@acme.example')->count());
        $this->assertSame([], $this->keycloakAdmin->deleted);
        $this->assertTrue(UserAdminAudit::query()->where([
            'action' => 'create',
            'outcome' => 'failed',
            'error_code' => 'IDP_CONFLICT',
        ])->whereNull('target_user_id')->exists());
    }

    public function test_keycloak_down_list_and_detail_do_not_fall_back_locally(): void
    {
        $this->keycloakAdmin->unavailable = true;

        $this->problem(
            $this->asAdmin()->getJson('/api/v1/admin/users'),
            503,
            'IDP_UNAVAILABLE',
            '/admin/users',
        );
        $this->problem(
            $this->asAdmin()->getJson("/api/v1/admin/users/{$this->dave->public_id}"),
            503,
            'IDP_UNAVAILABLE',
            '/admin/users/{userId}',
        );

        $this->assertNotNull(User::query()->find($this->dave->id));
        $this->spec($this->api($this->carol)->getJson('/api/v1/users'), '/users')->assertOk()
            ->assertJsonFragment(['id' => $this->alice->public_id]);
    }

    public function test_user_management_off_is_404_and_hr_cannot_call_it(): void
    {
        $this->problem(
            $this->api($this->carol)->getJson('/api/v1/admin/users'),
            403,
            'FORBIDDEN',
            '/admin/users',
        );

        $this->setting('modules.disabled', ['user_management']);

        $this->problem(
            $this->asAdmin()->getJson('/api/v1/admin/users'),
            404,
            'NOT_FOUND',
            '/admin/users',
        );
        $this->problem(
            $this->asAdmin()->postJson('/api/v1/admin/users', [
                'username' => 'erin.p',
                'email' => 'erin.p@acme.example',
                'first_name' => 'Erin',
                'last_name' => 'Patel',
            ]),
            404,
            'NOT_FOUND',
            '/admin/users',
            'post',
        );
        $this->problem(
            $this->asAdmin()->getJson("/api/v1/admin/users/{$this->dave->public_id}"),
            404,
            'NOT_FOUND',
            '/admin/users/{userId}',
        );

        $this->assertDatabaseMissing('users', ['email' => 'erin.p@acme.example']);
        $this->spec($this->api($this->carol)->getJson('/api/v1/users'), '/users')->assertOk();
    }

    public function test_local_insert_failure_deletes_the_keycloak_user(): void
    {
        $existing = User::factory()->create([
            'email' => 'gia.m@acme.example',
            'name' => 'Gia Existing',
        ]);

        $this->problem(
            $this->asAdmin()->postJson('/api/v1/admin/users', [
                'username' => 'gia.m',
                'email' => 'gia.m@acme.example',
                'first_name' => 'Gia',
                'last_name' => 'Mehta',
            ]),
            409,
            'IDP_CONFLICT',
            '/admin/users',
            'post',
        );

        $this->assertSame($existing->keycloak_sub, $existing->refresh()->keycloak_sub);
        $this->assertSame(1, User::query()->where('email', 'gia.m@acme.example')->count());
        $this->assertCount(1, $this->keycloakAdmin->deleted);
        $this->assertArrayNotHasKey($this->keycloakAdmin->deleted[0], $this->keycloakAdmin->users);
        $this->assertTrue(UserAdminAudit::query()->where([
            'actor_id' => $this->dave->id,
            'keycloak_user_id' => $this->keycloakAdmin->deleted[0],
            'action' => 'compensate_delete',
            'outcome' => 'success',
        ])->exists());
        $this->assertTrue(UserAdminAudit::query()->where([
            'actor_id' => $this->dave->id,
            'action' => 'create',
            'outcome' => 'failed',
            'error_code' => 'IDP_CONFLICT',
        ])->exists());
    }

    public function test_roles_outside_the_allow_list_are_rejected(): void
    {
        foreach ([['intern'], ['admin'], ['employee', 'manager']] as $roles) {
            $this->problem(
                $this->asAdmin()->putJson("/api/v1/admin/users/{$this->alice->public_id}/roles", [
                    'roles' => $roles,
                ]),
                422,
                'VALIDATION_FAILED',
                '/admin/users/{userId}/roles',
                'put',
            );
        }

        $this->assertSame('employee', $this->alice->refresh()->role->value);
        $this->assertFalse($this->alice->is_admin);
    }

    public function test_existing_leave_days_stay_put_when_the_weekend_changes(): void
    {
        // The happy path already checks one override. This pins a second invalid-then-valid
        // sequence: a rejected set must not touch the stored day count either.
        $created = $this->apply($this->alice)->assertCreated();
        $id = $created->json('data.id');
        $days = LeaveRequest::query()->where('public_id', $id)->value('days');

        $this->api($this->carol)->patchJson('/api/v1/settings/working-week', [
            'weekend_days' => [1, 1],
        ])->assertUnprocessable();
        $this->api($this->carol)->patchJson('/api/v1/settings/working-week', [
            'weekend_days' => [5, 6],
        ])->assertOk();

        $this->assertEquals($days, LeaveRequest::query()->where('public_id', $id)->value('days'));
    }

    private function armClearedAdminFlag(User $actor): void
    {
        User::query()->whereKey($actor->id)->update(['is_admin' => false]);
        User::saved(function (User $user) use ($actor): void {
            if ($user->id !== $actor->id || $user->is_admin !== true) {
                return;
            }
            User::withoutEvents(function () use ($user): void {
                User::query()->whereKey($user->id)->update(['is_admin' => false]);
            });
        });
        // The guard is built with whatever provisioner is bound. Rebind is unnecessary;
        // forgetGuards forces the next request to provision again so the listener runs.
        auth()->forgetGuards();
        $this->app->forgetInstance(KeycloakUserProvisioner::class);
    }

    private function createIdentityUser(string $username, string $email, bool $admin = false): User
    {
        $payload = [
            'username' => $username,
            'email' => $email,
            'first_name' => 'Frank',
            'last_name' => 'Admin',
            'role' => 'employee',
        ];
        if ($admin) {
            $payload['is_admin'] = true;
        }

        $created = $this->spec(
            $this->asAdmin()->postJson('/api/v1/admin/users', $payload),
            '/admin/users',
            'post',
        )->assertCreated();

        return User::query()->where('public_id', $created->json('data.id'))->firstOrFail();
    }

    private function fakeBrandDisk(): Filesystem
    {
        $name = (string) config('branding.disk');
        Storage::fake($name);

        return Storage::disk($name);
    }

    private function asAdmin(): static
    {
        return $this->tokenFor($this->dave, true);
    }

    private function tokenFor(User $user, bool $admin = false): static
    {
        $roles = [$user->role->value];
        if ($admin || $user->is_admin) {
            $roles[] = 'admin';
        }

        return $this->withToken($this->jwt(
            ['sub' => $user->keycloak_sub, 'email' => $user->email, 'name' => $user->name],
            $roles,
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

    private static function pngUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::pngBytes());
    }

    private static function pngBytes(): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        self::assertNotFalse($bytes);

        return $bytes;
    }
}
