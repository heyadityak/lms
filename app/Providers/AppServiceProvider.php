<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\JwksCache;
use App\Auth\KeycloakJwtGuard;
use App\Auth\KeycloakUserProvisioner;
use App\Models\Setting;
use App\Policies\SettingsPolicy;
use App\Support\Keycloak\HttpKeycloakAdminClient;
use App\Support\Keycloak\KeycloakAdminClient;
use App\Support\Modules;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per request under Octane (D-27): settings/modules memoise for one request only.
        $this->app->scoped(Settings::class);
        $this->app->scoped(Modules::class);
        // Tests bind FakeKeycloakAdminClient. Nothing else talks to the Admin REST API (D-22).
        $this->app->bind(KeycloakAdminClient::class, HttpKeycloakAdminClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // SettingsPolicy is not auto-discovered (there is no Settings model; Setting is the row).
        Gate::policy(Setting::class, SettingsPolicy::class);

        // Absolute URLs (Location, logo_url, mail links) always use the public origin, also when the BFF calls
        // through an internal host (D-43). Constant config values, so this is Octane-safe.
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme((string) parse_url((string) config('app.url'), PHP_URL_SCHEME));

        // Keycloak (D-20, D-43): Bearer JWT guard `auth:keycloak-jwt` is the only way in.
        // New guard per auth manager; Octane flushes the manager per request and setRequest() drops the cached user.
        Auth::extend('keycloak-jwt', function ($app): KeycloakJwtGuard {
            $guard = new KeycloakJwtGuard($app['request'], $app->make(JwksCache::class), $app->make(KeycloakUserProvisioner::class));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        RateLimiter::for('public', fn (Request $request): Limit => Limit::perMinute(300)->by($request->ip()));
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
