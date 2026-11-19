<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Alias `active` (USE_CASES §3.9): a deactivated user gets 401; the BFF then clears its session cookie (D-43). */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->is_active) {
            throw new AuthenticationException('Your account is deactivated.');
        }

        return $next($request);
    }
}
