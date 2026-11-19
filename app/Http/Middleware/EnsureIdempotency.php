<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` (API_GUIDELINES §7), alias `idempotent`. Slot `idem:{user}:{sha256(key)}` in the
 * cache store (Redis DB 1): SET NX a processing marker, run the request, store the outcome for 24 h.
 * Same key + same request ⇒ replay with `Idempotent-Replayed: true`; different request ⇒ 422
 * IDEMPOTENCY_KEY_REUSED; still processing ⇒ 409 IDEMPOTENCY_IN_PROGRESS. Only 2xx and 422 are kept.
 */
final class EnsureIdempotency
{
    private const TTL = 86400;

    /** A crashed worker must not block the key for 24 h; also bounds how long a duplicate sees 409. */
    private const PROCESSING_TTL = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');
        if ($key === null || $request->isMethodSafe()) {
            return $next($request);
        }
        if (! preg_match('/^[\x21-\x7E]{8,255}$/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['Must be 8-255 visible ASCII characters.']]);
        }

        $store = Cache::store();
        $slot = 'idem:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.hash('sha256', $key);
        // Method + path are part of the fingerprint, so reusing a key on another endpoint is a mismatch too.
        $hash = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());

        if (! $store->add($slot, ['hash' => $hash], self::PROCESSING_TTL)) {
            return $this->existing($store->get($slot), $hash);
        }

        $response = $next($request);
        $status = $response->getStatusCode();
        $body = $response->getContent();

        if (($status < 300 || $status === 422) && $body !== false) {
            $headers = $response->headers->allPreserveCaseWithoutCookies();
            unset($headers['Date']);
            $store->put($slot, ['hash' => $hash, 'status' => $status, 'headers' => $headers, 'body' => $body], self::TTL);
        } else {
            $store->forget($slot); // 5xx and other 4xx release the key so the client can retry
        }

        return $response;
    }

    /** @param  array{hash: string, status?: int, headers?: array<string, list<string>>, body?: string}|null  $saved */
    private function existing(?array $saved, string $hash): Response
    {
        if ($saved !== null && ! hash_equals($saved['hash'], $hash)) {
            throw new DomainException(
                'This Idempotency-Key was already used with a different request body.', 'IDEMPOTENCY_KEY_REUSED', 422,
                ['Idempotency-Key' => ['Key reused with a different payload.']],
            );
        }
        if (! isset($saved['status'])) {
            // Still processing (or the marker expired between add and get).
            throw new DomainException('A request with this Idempotency-Key is still being processed.', 'IDEMPOTENCY_IN_PROGRESS', 409, headers: ['Retry-After' => 1]);
        }

        return new Response($saved['body'], $saved['status'], ['Idempotent-Replayed' => 'true'] + $saved['headers']);
    }
}
