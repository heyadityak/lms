<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Module;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `module:<key>`: 404 when the module is off (D-17; problem+json on /api/*). Runs before auth (D-40 g). */
final class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(app(Modules::class)->enabled(Module::from($module)), 404);

        return $next($request);
    }
}
