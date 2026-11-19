<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Guard `keycloak-jwt` (D-20): stateless Bearer access token issued by Keycloak → local User.
 * One instance per request (Octane flushes guards; setRequest() drops the cached user).
 * Invalid token or inactive user → null (401); token without an LMS base role → AuthorizationException (403).
 */
final class KeycloakJwtGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        private Request $request,
        private readonly JwksCache $jwks,
        private readonly KeycloakUserProvisioner $provisioner,
    ) {}

    public function user(): ?User
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->request->bearerToken();
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $claims = $this->decode($token);
        } catch (Throwable $e) {
            Log::notice('auth.jwt_rejected', ['reason' => $e->getMessage()]);

            return null;
        }

        $user = $this->provisioner->provision($claims);
        if (! $user->is_active) {
            Log::notice('auth.jwt_rejected', ['reason' => 'inactive user', 'user_id' => $user->id]);

            return null;
        }

        return $this->user = $user;
    }

    /** @param  array<string, mixed>  $credentials  ['token' => jwt] */
    public function validate(array $credentials = []): bool
    {
        try {
            $this->decode((string) ($credentials['token'] ?? ''));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;
        $this->user = null;

        return $this;
    }

    /**
     * Verify an access token: RS256 only, signature via the cached JWKS, exp/nbf with leeway, iss, aud/azp.
     *
     * @return array<string, mixed> verified claims
     *
     * @throws UnexpectedValueException|Throwable on any invalid token
     */
    public function decode(string $jwt): array
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]), true);
        // Pin the algorithm before touching keys: rejects alg=none and HS* key-confusion tokens.
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new UnexpectedValueException('Unsupported JWT header');
        }

        $kid = $header['kid'];
        $jwk = $this->jwks->keys()[$kid] ?? $this->jwks->refresh()[$kid] ?? null;
        if ($jwk === null) {
            throw new UnexpectedValueException('Unknown kid');
        }

        // php-jwt only supports leeway as a static; it is always the same config value, so no request state leaks.
        JWT::$leeway = (int) config('keycloak.leeway');
        $claims = json_decode(json_encode(JWT::decode($jwt, JWK::parseKey($jwk, 'RS256')), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        if (! isset($claims['exp'])) {
            throw new UnexpectedValueException('Missing exp');
        }
        // ID and refresh tokens are signed with the same keys; only access tokens are accepted.
        if (($claims['typ'] ?? null) !== 'Bearer') {
            throw new UnexpectedValueException('Not an access token');
        }
        if (($claims['iss'] ?? null) !== self::issuer()) {
            throw new UnexpectedValueException('Invalid issuer');
        }
        $audiences = [...(array) ($claims['aud'] ?? []), (string) ($claims['azp'] ?? '')];
        if (array_intersect($audiences, config('keycloak.allowed_audiences')) === []) {
            throw new UnexpectedValueException('Invalid audience');
        }

        return $claims;
    }

    public static function issuer(): string
    {
        return config('keycloak.public_url').'/realms/'.config('keycloak.realm');
    }
}
