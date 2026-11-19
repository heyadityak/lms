<?php

declare(strict_types=1);

use App\Exceptions\ProblemJsonRenderer;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RequestId;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/auth.php'));
            // Outside the web group: no session, cookies or CSRF.
            Route::get('/healthz', [HealthController::class, 'live']);
            Route::get('/readyz', [HealthController::class, 'ready']);
        },
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
            // Domain adds: 'active' => EnsureUserIsActive.
        ]);
        // Auth is in Laravel's priority list and would otherwise run first: a disabled module must 404, not 401.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureModuleEnabled::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ProblemJsonRenderer);
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api', 'api/*') || $request->expectsJson());
    })->create();
