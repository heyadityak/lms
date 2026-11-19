<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` for unsafe API methods. Stores the first completed (<500) response for 24h
 * in the cache store (Redis) and replays it; same key + different payload ⇒ 409.
 */
final class Idempotency
{
    private const TTL = 86400;

    private const LOCK_TTL = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');
        if ($key === null || $request->isMethodSafe()) {
            return $next($request);
        }
        if (! preg_match('/^[\x21-\x7E]{1,255}$/', $key)) {
            throw new DomainException('Idempotency-Key must be 1-255 visible ASCII characters.', 'idempotency_key_invalid', 400);
        }

        $scope = 'idem:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.hash('sha256', $key);
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());
        $store = Cache::store();

        if ($saved = $store->get($scope)) {
            return $this->replay($saved, $fingerprint);
        }

        if (! $store->add($scope.':lock', 1, self::LOCK_TTL)) {
            throw new DomainException('A request with this Idempotency-Key is still being processed.', 'idempotency_in_progress', 409);
        }

        try {
            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                $store->put($scope, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'headers' => array_filter([
                        'Content-Type' => $response->headers->get('Content-Type'),
                        'Location' => $response->headers->get('Location'),
                    ]),
                    'body' => $response->getContent(),
                ], self::TTL);
            }

            return $response;
        } finally {
            $store->forget($scope.':lock');
        }
    }

    /** @param  array{fingerprint: string, status: int, headers: array<string, string>, body: string}  $saved */
    private function replay(array $saved, string $fingerprint): Response
    {
        if (! hash_equals($saved['fingerprint'], $fingerprint)) {
            throw new DomainException('Idempotency-Key was already used with a different request.', 'idempotency_key_reused', 409);
        }

        return new Response($saved['body'], $saved['status'], $saved['headers'] + ['Idempotent-Replayed' => 'true']);
    }
}
