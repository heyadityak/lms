<?php

declare(strict_types=1);

namespace Tests\Feature\WhiteLabel;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** Public `GET /api/v1/branding` (getPublicBranding) and `GET /branding/{asset}` (D-43). */
final class PublicBrandingTest extends TestCase
{
    use RefreshDatabase, ValidatesOpenApi;

    public function test_defaults_without_token_match_the_spec_with_cache_and_rate_limit_headers(): void
    {
        config(['app.url' => 'http://localhost:8080']);
        $response = $this->getJson('/api/v1/branding')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=30, public')
            ->assertHeader('X-RateLimit-Limit', '300')
            ->assertExactJson(['data' => [
                'name' => 'Leave Manager',
                'primary_color' => '#1f6feb',
                'accent_color' => '#0e8a16',
                'primary_contrast' => '#ffffff',
                'logo_url' => url('/branding/logo').'?v=default',
                'favicon_url' => url('/branding/favicon').'?v=default',
                'modules' => ['holidays', 'reports', 'api', 'notifications', 'user_management'],
            ], 'meta' => [], 'links' => []]);

        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/branding/logo?v=', $response->json('data.logo_url'));
        $this->assertSame('{}', json_encode($response->original['meta'])); // object, not []
        $this->assertMatchesSpec($response, '/branding');
    }

    public function test_overrides_disabled_modules_and_api_module_off_do_not_hide_it(): void
    {
        $settings = app(Settings::class);
        $settings->set('brand.name', 'Acme HR');
        $settings->set('brand.primary_color', '#ffd700');
        $settings->set('brand.logo_path', 'logo-abc.png');
        $settings->set('modules.disabled', ['reports', 'api']);
        $this->app->forgetScopedInstances();

        $response = $this->getJson('/api/v1/branding')->assertOk()
            ->assertJsonPath('data.name', 'Acme HR')
            ->assertJsonPath('data.primary_contrast', '#000000')
            ->assertJsonPath('data.logo_url', url('/branding/logo').'?v='.substr(sha1('logo-abc.png'), 0, 12))
            ->assertJsonPath('data.modules', ['holidays', 'notifications', 'user_management'])
            ->assertJsonMissingPath('data.overridden');
        $this->assertMatchesSpec($response, '/branding');
    }

    public function test_invalid_stored_colour_falls_back_to_default(): void
    {
        Setting::query()->create(['key' => 'brand.primary_color', 'value' => 'red;}body{display:none']); // bypasses validation

        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('data.primary_color', '#1f6feb');
    }

    public function test_absolute_urls_use_app_url_even_via_an_internal_host(): void
    {
        // The BFF reaches Laravel as http://nginx; links must still point at the public origin (URL::forceRootUrl).
        $this->get('http://nginx/api/v1/branding')->assertOk()
            ->assertJsonPath('data.favicon_url', rtrim(config('app.url'), '/').'/branding/favicon?v=default');
    }

    public function test_public_limiter_is_per_ip(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->getJson('/api/v1/branding', ['REMOTE_ADDR' => '10.0.0.1']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson('/api/v1/branding')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson('/api/v1/branding')->assertOk();
    }

    public function test_assets_serve_bundled_defaults_with_cache_headers_and_etag(): void
    {
        $logo = $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('max-age=300', (string) $logo->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $logo->headers->get('Cache-Control'));
        $this->assertSame(file_get_contents(public_path('img/default-logo.png')), $logo->baseResponse->getFile()->getContent());

        $this->get('/branding/logo', ['If-None-Match' => $logo->headers->get('ETag')])->assertStatus(304);
        $this->get('/branding/favicon')->assertOk();
        $this->get('/branding/other')->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
        // No session or cookies on Laravel (D-43).
        $this->assertSame([], $logo->headers->getCookies());
    }

    public function test_asset_serves_the_uploaded_override_and_falls_back_when_it_is_missing(): void
    {
        Storage::fake(config('branding.disk'));
        Storage::disk(config('branding.disk'))->put('logo-abc.png', 'uploaded-logo-bytes');
        app(Settings::class)->set('brand.logo_path', 'logo-abc.png');
        $this->app->forgetScopedInstances();

        $override = $this->get('/branding/logo')->assertOk();
        $this->assertSame(Storage::disk(config('branding.disk'))->get('logo-abc.png'), $override->streamedContent());

        Storage::disk(config('branding.disk'))->delete('logo-abc.png');
        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
