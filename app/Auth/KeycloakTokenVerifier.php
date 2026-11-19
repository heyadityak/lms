<?php

declare(strict_types=1);

namespace App\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * Validates Keycloak-issued Bearer JWTs: RS256 signature against the realm JWKS
 * (cached, refetched on unknown kid), iss, aud/azp, exp/nbf with leeway.
 */
final class KeycloakTokenVerifier
{
    private const CACHE_KEY = 'keycloak:jwks';

    /**
     * @return array<string, mixed> verified claims
     *
     * @throws UnexpectedValueException on any invalid token
     */
    public function verify(string $jwt): array
    {
        JWT::$leeway = (int) config('keycloak.leeway');

        try {
            $claims = (array) JWT::decode($jwt, $this->keys());
        } catch (UnexpectedValueException $e) {
            // Unknown kid ⇒ Keycloak may have rotated keys: refetch once (throttled).
            if (! str_contains($e->getMessage(), '"kid"') || ! Cache::add(self::CACHE_KEY.':refetch', 1, 10)) {
                throw $e;
            }
            Cache::forget(self::CACHE_KEY);
            $claims = (array) JWT::decode($jwt, $this->keys());
        }

        // Access tokens only; ID/refresh tokens are signed with the same keys.
        if (($claims['typ'] ?? null) !== 'Bearer') {
            throw new UnexpectedValueException('Not an access token');
        }

        if (($claims['iss'] ?? null) !== self::issuer()) {
            throw new UnexpectedValueException('Invalid issuer');
        }

        $audiences = array_merge((array) ($claims['aud'] ?? []), [(string) ($claims['azp'] ?? '')]);
        if (! array_intersect($audiences, config('keycloak.allowed_audiences'))) {
            throw new UnexpectedValueException('Invalid audience');
        }

        return json_decode(json_encode($claims, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function issuer(): string
    {
        return config('keycloak.public_url').'/realms/'.config('keycloak.realm');
    }

    public static function jwksUrl(): string
    {
        return config('keycloak.base_url').'/realms/'.config('keycloak.realm').'/protocol/openid-connect/certs';
    }

    /** @return array<string, Key> */
    private function keys(): array
    {
        $jwks = Cache::remember(self::CACHE_KEY, (int) config('keycloak.jwks_cache_ttl'), function (): array {
            $keys = Http::timeout(5)->retry(2, 200)->get(self::jwksUrl())->throw()->json('keys', []);

            // Only RS256 signing keys; Keycloak also publishes RSA-OAEP encryption keys.
            return ['keys' => array_values(array_filter($keys, fn (array $k): bool => ($k['use'] ?? 'sig') === 'sig' && ($k['alg'] ?? 'RS256') === 'RS256'))];
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
