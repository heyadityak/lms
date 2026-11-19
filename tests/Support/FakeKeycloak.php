<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\JwksCache;
use App\Auth\KeycloakJwtGuard;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/** Test RSA keypair + faked realm JWKS (Http::fake), so tests never need a live Keycloak. */
trait FakeKeycloak
{
    protected string $kid = 'test-kid';

    protected OpenSSLAsymmetricKey $privateKey;

    /** @var list<array<string, string>> what the faked certs endpoint serves; tests may rotate it */
    protected array $publishedKeys = [];

    protected function fakeKeycloak(): void
    {
        $this->privateKey = self::newKey();
        $this->publishedKeys = [
            $this->jwk($this->privateKey, $this->kid),
            // Keycloak also publishes an encryption key; it must never verify a signature.
            ['use' => 'enc', 'alg' => 'RSA-OAEP'] + $this->jwk($this->privateKey, 'enc-kid'),
        ];

        Http::fake([JwksCache::url() => fn () => Http::response(['keys' => $this->publishedKeys])]);
    }

    /** @return array<string, string> */
    protected function jwk(OpenSSLAsymmetricKey $key, string $kid): array
    {
        $rsa = openssl_pkey_get_details($key)['rsa'];

        return ['kid' => $kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e'])];
    }

    /**
     * A Keycloak-shaped access token (realm_access.roles, typ Bearer).
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $roles
     */
    protected function jwt(array $overrides = [], array $roles = ['default-roles-lms', 'offline_access', 'employee'], ?OpenSSLAsymmetricKey $key = null, ?string $kid = null): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => KeycloakJwtGuard::issuer(),
            'aud' => 'account',
            'azp' => 'lms-api-cli',
            'typ' => 'Bearer',
            'sub' => 'sub-alice',
            'email' => 'Alice@Example.test',
            'email_verified' => true,
            'name' => 'Alice Employee',
            'realm_access' => ['roles' => $roles],
            'iat' => $now,
            'exp' => $now + 300,
        ], $overrides), $key ?? $this->privateKey, 'RS256', $kid ?? $this->kid);
    }

    /** Raw unsigned/forged token with an arbitrary header. */
    protected function forged(array $header, array $claims, string $signature = ''): string
    {
        $enc = fn (array $part): string => JWT::urlsafeB64Encode(json_encode($part, JSON_THROW_ON_ERROR));

        return $enc($header).'.'.$enc($claims).'.'.JWT::urlsafeB64Encode($signature);
    }

    protected static function newKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }
}
