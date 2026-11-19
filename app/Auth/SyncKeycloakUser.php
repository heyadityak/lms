<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Upserts the local user from Keycloak claims. Keycloak owns identity and roles;
 * users.role is a read cache of the highest known realm role, refreshed on every login.
 */
final class SyncKeycloakUser
{
    /**
     * @param  array<string, mixed>  $claims  sub, email, name|preferred_username, roles
     *
     * @throws AuthorizationException when the token carries no LMS role
     */
    public function handle(array $claims): User
    {
        $role = self::resolveRole((array) ($claims['roles'] ?? []));
        if ($role === null || empty($claims['sub']) || empty($claims['email'])) {
            throw new AuthorizationException('No LMS role assigned in Keycloak.');
        }

        return DB::transaction(function () use ($claims, $role): User {
            $user = User::query()->lockForUpdate()->firstWhere('keycloak_sub', $claims['sub'])
                ?? new User(['keycloak_sub' => $claims['sub']]);

            $user->forceFill([
                'keycloak_sub' => (string) $claims['sub'],
                'email' => mb_strtolower((string) $claims['email']),
                'name' => (string) ($claims['name'] ?? $claims['preferred_username'] ?? $claims['email']),
                'role' => $role,
            ]);

            if ($user->isDirty()) {
                $created = ! $user->exists;
                $user->save();
                Log::info($created ? 'auth.user_created' : 'auth.user_synced', ['user_id' => $user->id, 'role' => $role]);
            }

            return $user;
        });
    }

    /** @param  array<int, mixed>  $roles */
    public static function resolveRole(array $roles): ?string
    {
        foreach (config('keycloak.roles') as $known) {
            if (in_array($known, $roles, true)) {
                return $known;
            }
        }

        return null;
    }
}
