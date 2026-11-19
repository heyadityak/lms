<?php

declare(strict_types=1);

use App\Exceptions\ProblemJsonRenderer;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequestId;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // API-only (D-43): routes/web.php is loaded outside the `web` group, so no session, cookies or CSRF.
        then: fn () => Route::group([], base_path('routes/web.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies come from config/trustedproxy.php (TRUSTED_PROXIES).
        $middleware->prepend(RequestId::class);
        // `throttle` = cache RateLimiter: Redis (cache store, DB 1) in every deployment, `array` in tests,
        // so counters never leak between test runs (throttleWithRedis would always hit real Redis, D-40).
        $middleware->alias([
            'role' => EnsureRole::class,
            'admin' => EnsureAdmin::class,
            'module' => EnsureModuleEnabled::class,
            'idempotent' => EnsureIdempotency::class,
            'active' => EnsureUserIsActive::class,
        ]);
        // No login route (D-43): unauthenticated is always a 401 problem, never a redirect.
        $middleware->redirectGuestsTo(fn (): ?string => null);
        // Auth is in Laravel's priority list and would otherwise run first: a disabled module must 404, not 401.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureModuleEnabled::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ProblemJsonRenderer);
        // Laravel renders no HTML pages (D-43): every error, also on /healthz or /branding/*, is problem+json.
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);
    })->create();
