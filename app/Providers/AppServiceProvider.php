<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\JwksCache;
use App\Auth\KeycloakJwtGuard;
use App\Auth\KeycloakProvider;
use App\Auth\KeycloakUserProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Keycloak (D-20): web login via Socialite, API via Bearer JWT guard `auth:keycloak-jwt`.
        Event::listen(fn (SocialiteWasCalled $event) => $event->extendSocialite('keycloak', KeycloakProvider::class));
        // New guard per auth manager; Octane flushes the manager per request and setRequest() drops the cached user.
        Auth::extend('keycloak-jwt', function ($app): KeycloakJwtGuard {
            $guard = new KeycloakJwtGuard($app['request'], $app->make(JwksCache::class), $app->make(KeycloakUserProvisioner::class));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
