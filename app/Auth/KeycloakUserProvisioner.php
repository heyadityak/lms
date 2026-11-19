<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * First-login provisioning + role sync from verified Keycloak claims (D-20, D-29).
 * Keycloak wins: users.role (base role) and users.is_admin are caches refreshed on every login/API call.
 * Does not check is_active; callers refuse inactive users (web 403, API 401).
 */
final class KeycloakUserProvisioner
{
    /** Base realm roles, highest first (D-29). Domain may replace this with the Role enum. */
    public const BASE_ROLES = ['hr_admin', 'manager', 'employee'];

    public const ADMIN_ROLE = 'admin';

    /**
     * @param  array<string, mixed>  $claims  verified token claims: sub, email, email_verified, name, realm_access.roles
     *
     * @throws AuthorizationException when the user holds no base role (also: `admin` alone) or has no sub/email
     */
    public function provision(array $claims): User
    {
        $roles = (array) data_get($claims, 'realm_access.roles', []);
        $base = self::baseRole($roles);
        $sub = (string) ($claims['sub'] ?? '');
        $email = mb_strtolower((string) ($claims['email'] ?? ''));

        if ($base === null || $sub === '' || $email === '') {
            throw new AuthorizationException('Your account has no Leave Manager role. Ask your administrator.');
        }

        $user = User::query()->firstWhere('keycloak_sub', $sub);
        if ($user === null && ($user = User::query()->firstWhere('email', $email)) !== null) {
            // Link a pre-created user (no sub yet) or re-link a stale sub (user re-created in Keycloak),
            // but only through a Keycloak-verified email; emails are unique in the realm.
            if (($claims['email_verified'] ?? false) !== true) {
                throw new AuthorizationException('Your email address is not verified. Ask your administrator.');
            }
            if ($user->keycloak_sub !== null) {
                Log::warning('auth.user_relinked', ['user_id' => $user->id, 'old_sub' => $user->keycloak_sub, 'new_sub' => $sub]);
            }
        }
        $user ??= new User;

        $user->forceFill([
            'keycloak_sub' => $sub,
            'email' => $email,
            'name' => (string) ($claims['name'] ?? $claims['preferred_username'] ?? $email),
            'role' => $base,
            'is_admin' => in_array(self::ADMIN_ROLE, $roles, true),
        ]);

        if (! $user->isDirty()) {
            return $user;
        }

        $created = ! $user->exists;
        try {
            $user->save();
        } catch (UniqueConstraintViolationException $e) {
            // Two first requests raced on the same sub: the other one inserted, use its row.
            // Any other conflict (e.g. email already linked to another sub) is a real error.
            if ($created && User::query()->where('keycloak_sub', $sub)->exists()) {
                return $this->provision($claims);
            }

            throw $e;
        }

        Log::info($created ? 'auth.user_provisioned' : 'auth.user_synced', ['user_id' => $user->id, 'role' => $base, 'is_admin' => $user->is_admin]);

        return $user;
    }

    /** @param  array<int, mixed>  $roles */
    public static function baseRole(array $roles): ?string
    {
        foreach (self::BASE_ROLES as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }
}
