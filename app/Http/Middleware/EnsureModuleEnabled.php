<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:<key>`: 404 when the module is off (problem+json on /api/*).
 *
 * shortcut: platform stub, env licence + registry only (config/modules.php). Phase 5 (domain) replaces the
 * condition with app(Modules::class)->enabled(Module::from($module)) to add the DB `modules.disabled` override.
 */
final class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(
            in_array($module, config('modules.licensed', []), true) && config("modules.registry.{$module}.available", false),
            404,
        );

        return $next($request);
    }
}
