<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * shortcut: platform stub so `module:<key>` resolves; checks only the licensed env list.
 * Domain replaces the body with app(Modules::class)->enabled(Module::from($module)) (D-14/D-17).
 */
final class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $licensed = config('modules.licensed');
        abort_if(is_array($licensed) && ! in_array($module, $licensed, true), 404);

        return $next($request);
    }
}
