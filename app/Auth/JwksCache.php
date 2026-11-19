<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Realm JWKS cached in the shared cache store (Redis in prod), so every replica
 * validates tokens without calling Keycloak and survives short Keycloak outages.
 * Stateless: holds nothing between calls (Octane-safe).
 */
final class JwksCache
{
    public const CACHE_KEY = 'keycloak:jwks';

    public const REFRESH_LOCK = 'keycloak:jwks-refresh';

    /** At most one forced refetch per this many seconds across all replicas. */
    public const REFRESH_INTERVAL = 10;

    /** @return array<string, array<string, mixed>> raw JWKs by kid (RS256 signing keys only) */
    public function keys(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('keycloak.jwks_cache_ttl'), fn (): array => $this->fetch());
    }

    /**
     * Refetch after an unknown kid (key rotation). The lock is never released, so it
     * doubles as a rate limit: a stream of bogus kids costs Keycloak one call per interval.
     *
     * @return array<string, array<string, mixed>>
     */
    public function refresh(): array
    {
        if (! Cache::lock(self::REFRESH_LOCK, self::REFRESH_INTERVAL)->get()) {
            return $this->keys();
        }

        $keys = $this->fetch();
        Cache::put(self::CACHE_KEY, $keys, (int) config('keycloak.jwks_cache_ttl'));

        return $keys;
    }

    public static function url(): string
    {
        return config('keycloak.base_url').'/realms/'.config('keycloak.realm').'/protocol/openid-connect/certs';
    }

    /** @return array<string, array<string, mixed>> */
    private function fetch(): array
    {
        $keys = [];
        foreach (Http::timeout(5)->get(self::url())->throw()->json('keys', []) as $jwk) {
            // Keycloak also publishes RSA-OAEP encryption keys; only RS256 signing keys verify tokens.
            if (isset($jwk['kid']) && ($jwk['kty'] ?? null) === 'RSA' && ($jwk['use'] ?? 'sig') === 'sig' && ($jwk['alg'] ?? 'RS256') === 'RS256') {
                $keys[(string) $jwk['kid']] = $jwk;
            }
        }

        return $keys;
    }
}
