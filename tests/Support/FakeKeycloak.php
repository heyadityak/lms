<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\KeycloakTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/** Test RSA keypair + faked realm JWKS, so feature tests never need a live Keycloak. */
trait FakeKeycloak
{
    protected string $kid = 'test-kid';

    protected \OpenSSLAsymmetricKey $privateKey;

    protected function fakeKeycloak(): void
    {
        $this->privateKey = self::newKey();
        $d = openssl_pkey_get_details($this->privateKey)['rsa'];
        $b64 = fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        Http::fake([KeycloakTokenVerifier::jwksUrl() => Http::response(['keys' => [
            ['kid' => $this->kid, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($d['n']), 'e' => $b64($d['e'])],
            // Keycloak also publishes an encryption key; it must be ignored.
            ['kid' => 'enc-kid', 'kty' => 'RSA', 'alg' => 'RSA-OAEP', 'use' => 'enc', 'n' => $b64($d['n']), 'e' => $b64($d['e'])],
        ]])]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function jwt(array $overrides = [], ?\OpenSSLAsymmetricKey $key = null, ?string $kid = null): string
    {
        $now = time();

        return JWT::encode(array_merge([
            'iss' => KeycloakTokenVerifier::issuer(),
            'aud' => 'account',
            'azp' => 'lms-api-cli',
            'typ' => 'Bearer',
            'sub' => 'sub-alice',
            'email' => 'Alice@Example.test',
            'name' => 'Alice Employee',
            'roles' => ['default-roles-lms', 'employee'],
            'iat' => $now,
            'exp' => $now + 300,
        ], $overrides), $key ?? $this->privateKey, 'RS256', $kid ?? $this->kid);
    }

    protected static function newKey(): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }
}
