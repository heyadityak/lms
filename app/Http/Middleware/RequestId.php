<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Propagates nginx's X-Request-Id (or a fresh UUID) into log context and the response. */
final class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->headers->get('X-Request-Id');
        if (! preg_match('/^[A-Za-z0-9._-]{8,128}$/', $id)) {
            $id = (string) Str::uuid();
        }

        $request->headers->set('X-Request-Id', $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
