<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `role:hr_admin,manager` — coarse gate on the base role (users.role, synced from Keycloak). `admin` is a flag: use the `admin` middleware. Resource rules stay in Policies. */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;
        abort_unless($role !== null && in_array($role instanceof \BackedEnum ? $role->value : $role, $roles, true), 403);

        return $next($request);
    }
}
