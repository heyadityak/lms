<?php

declare(strict_types=1);

namespace App\Auth;

use App\Actions\Accrual\GrantInitialEntitlement;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
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
    public function __construct(private readonly GrantInitialEntitlement $grantInitialEntitlement) {}

    /**
     * @param  array<string, mixed>  $claims  verified token claims: sub, email, email_verified, name, realm_access.roles
     *
     * @throws AuthorizationException when the user holds no base role (also: `admin` alone) or has no sub/email
     */
    public function provision(array $claims): User
    {
        $roles = (array) data_get($claims, 'realm_access.roles', []);
        $base = Role::fromKeycloakRoles($roles);
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
            'is_admin' => in_array(Role::Admin->value, $roles, true),
        ]);
        // Throttled so API calls (provisioned on every request) do not write the row each time.
        if ($user->last_login_at === null || $user->last_login_at->lt(now()->subMinutes(5))) {
            $user->forceFill(['last_login_at' => now()]);
        }
        // Optional Keycloak user-attribute mapper; only fills an unknown joining date (HR owns it afterwards, §3.7).
        $joined = self::dateClaim($claims['date_of_joining'] ?? null);
        $grant = $user->date_of_joining === null && $joined !== null;
        if ($grant) {
            $user->forceFill(['date_of_joining' => $joined]);
        }

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

        if ($grant) {
            $this->grantInitialEntitlement->handle($user);
        }

        Log::info($created ? 'auth.user_provisioned' : 'auth.user_synced', ['user_id' => $user->id, 'role' => $base->value, 'is_admin' => $user->is_admin]);

        return $user;
    }

    private static function dateClaim(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->toDateString() === $value ? $value : null;
    }
}
