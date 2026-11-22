<?php

declare(strict_types=1);

namespace Tests\Feature\WhiteLabel;

use App\Enums\Module;
use App\Models\Setting;
use App\Models\User;
use App\Support\Modules;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SettingsModulesBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_env_default_db_override_and_cache_bust(): void
    {
        config(['branding.name' => 'Env Brand']);
        $this->assertSame('Env Brand', $this->freshSettings()->get('brand.name'));
        $this->assertSame([6, 7], $this->freshSettings()->get('work.weekend_days'));
        $this->assertTrue(Cache::has(Settings::CACHE_KEY));

        $actor = User::factory()->create();
        $this->freshSettings()->set('brand.name', 'Acme', $actor);
        $this->freshSettings()->set('work.weekend_days', [5, 6], $actor);
        // A new request (fresh scoped instance) sees the write, so the shared cache was busted.
        $this->assertSame('Acme', $this->freshSettings()->get('brand.name'));
        $this->assertSame([5, 6], $this->freshSettings()->get('work.weekend_days'));
        $this->assertSame($actor->id, Setting::query()->find('brand.name')->updated_by);

        $this->freshSettings()->forget('brand.name');
        $this->assertSame('Env Brand', $this->freshSettings()->get('brand.name'));
    }

    public function test_modules_are_licensed_and_available_and_not_disabled(): void
    {
        config(['modules.licensed' => ['holidays', 'api', 'reports', 'multi_level']]);
        $this->freshSettings()->set('modules.disabled', ['reports']);
        $modules = $this->app->make(Modules::class);

        $this->assertTrue($modules->enabled(Module::Holidays));
        $this->assertTrue($modules->enabled(Module::Api));
        $this->assertFalse($modules->enabled(Module::Reports)); // disabled by admin
        $this->assertFalse($modules->enabled(Module::Notifications)); // not licensed
        $this->assertTrue($modules->enabled(Module::MultiLevel)); // licensed and available
        $this->assertSame(['label' => 'Reports & exports', 'available' => true, 'licensed' => true, 'enabled' => false], $modules->all()['reports']);
    }

    public function test_disabled_module_route_is_404_before_auth(): void
    {
        $this->freshSettings()->set('modules.disabled', ['api']);

        $this->getJson('/api/v1/me')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_settings_and_modules_are_scoped_bindings(): void
    {
        foreach ([Settings::class, Modules::class] as $class) {
            $first = $this->app->make($class);
            $this->assertSame($first, $this->app->make($class)); // shared within a request
            $this->app->forgetScopedInstances(); // what Octane does after each request
            $this->assertNotSame($first, $this->app->make($class), "{$class} must be scoped, not a singleton (D-27).");
        }
    }

    private function freshSettings(): Settings
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(Settings::class);
    }
}
