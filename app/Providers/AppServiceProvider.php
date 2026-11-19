<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\KeycloakProvider;
use App\Auth\KeycloakTokenGuard;
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

        // Keycloak: web login via Socialite, API via Bearer JWT guard (`auth:keycloak`).
        Event::listen(fn (SocialiteWasCalled $event) => $event->extendSocialite('keycloak', KeycloakProvider::class));
        Auth::viaRequest('keycloak', fn (Request $request) => app(KeycloakTokenGuard::class)($request));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
