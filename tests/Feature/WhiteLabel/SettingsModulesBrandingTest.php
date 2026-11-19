<?php

declare(strict_types=1);

namespace Tests\Feature\WhiteLabel;

use App\Enums\Module;
use App\Models\Setting;
use App\Models\User;
use App\Support\Modules;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
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
        config(['modules.licensed' => ['holidays', 'api', 'reports', 'multi_level_approval']]);
        $this->freshSettings()->set('modules.disabled', ['reports']);
        $modules = $this->app->make(Modules::class);

        $this->assertTrue($modules->enabled(Module::Holidays));
        $this->assertTrue($modules->enabled(Module::Api));
        $this->assertFalse($modules->enabled(Module::Reports)); // disabled by admin
        $this->assertFalse($modules->enabled(Module::Notifications)); // not licensed
        $this->assertFalse($modules->enabled(Module::MultiLevelApproval)); // licensed but not available
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

    public function test_module_blade_directive_hides_markup(): void
    {
        $template = "@module('holidays')<a>Holidays</a>@endmodule<p>Core</p>";
        $this->assertStringContainsString('Holidays', Blade::render($template));

        $this->freshSettings()->set('modules.disabled', ['holidays']);
        $this->app->forgetScopedInstances(); // next request
        $html = Blade::render($template);
        $this->assertStringNotContainsString('Holidays', $html);
        $this->assertStringContainsString('Core', $html);
    }

    public function test_branding_is_rendered_as_css_variables(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<title>Leave Manager</title>', false)
            ->assertSee(':root{--brand-primary:#1f6feb;--brand-accent:#0e8a16;--brand-primary-contrast:#ffffff;}', false)
            ->assertSee(asset('img/default-logo.png'), false);

        $settings = $this->freshSettings();
        $settings->set('brand.name', 'Acme HR');
        $settings->set('brand.primary_color', '#ffd700');
        $settings->set('brand.logo_path', 'logo-abc.png');
        $this->app->forgetScopedInstances();

        $this->get('/')->assertOk()
            ->assertSee('<title>Acme HR</title>', false)
            ->assertSee('--brand-primary:#ffd700;--brand-accent:#0e8a16;--brand-primary-contrast:#000000;', false)
            ->assertSee(url('/branding/logo'), false);
    }

    public function test_invalid_stored_colour_falls_back_to_default(): void
    {
        // Bypasses validation, as a tampered row would.
        Setting::query()->create(['key' => 'brand.primary_color', 'value' => 'red;}body{display:none']);

        $this->get('/')->assertOk()
            ->assertSee('--brand-primary:#1f6feb;', false)
            ->assertDontSee('display:none', false);
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
