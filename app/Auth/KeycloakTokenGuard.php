<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Request guard callback for `auth:keycloak`: Bearer JWT → local User (synced from claims). */
final class KeycloakTokenGuard
{
    public function __construct(
        private readonly KeycloakTokenVerifier $verifier,
        private readonly SyncKeycloakUser $sync,
    ) {}

    public function __invoke(Request $request): ?User
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return null;
        }

        try {
            return $this->sync->handle($this->verifier->verify($token));
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::notice('auth.jwt_rejected', ['reason' => $e->getMessage()]);

            return null;
        }
    }
}
