<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\DomainException;
use App\Exceptions\IdpReadOnly;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/** Guardrails G2–G5 (USE_CASES §3.11). G1 is the policy. G6/G7 come back from Keycloak. */
final class UserAdminGuard
{
    private const BASE = [Role::Employee, Role::Manager, Role::HrAdmin];

    public function assertNotSelf(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new DomainException(
                'You cannot change your own roles or disable your own account.',
                'SELF_MODIFICATION_FORBIDDEN',
                403,
            );
        }
    }

    /** G3. Call under {@see lock()} so two demotions cannot both pass. `$removesAdmin` is also true for disable. */
    public function assertNotLastAdmin(User $target, bool $removesAdmin): void
    {
        if (! $removesAdmin || ! $target->is_admin || ! $target->is_active) {
            return;
        }

        $active = User::query()->where('is_admin', true)->where('is_active', true)->count();
        if ($active <= 1) {
            throw new DomainException(
                'The last active admin cannot lose admin access or be disabled.',
                'LAST_ADMIN',
                409,
            );
        }
    }

    /** G5. Federated (LDAP/AD) profile fields and password reset are read-only. */
    public function assertProfileEditable(array $kcUser): void
    {
        if (filled($kcUser['federationLink'] ?? null)) {
            throw new IdpReadOnly('This user is managed by an external directory and cannot be changed here.');
        }
    }

    /**
     * G4. Exactly one base role plus optional `admin`.
     *
     * @param  list<string>  $roles
     */
    public function assertAllowedRoles(array $roles): void
    {
        $allowed = [Role::Employee->value, Role::Manager->value, Role::HrAdmin->value, Role::Admin->value];
        $base = array_values(array_intersect($roles, array_map(fn (Role $role): string => $role->value, self::BASE)));
        $ok = $roles !== []
            && count($roles) <= 2
            && count($roles) === count(array_unique($roles))
            && count($base) === 1
            && array_diff($roles, $allowed) === [];

        if (! $ok) {
            throw new DomainException(
                'Choose exactly one base role (employee, manager or hr_admin), and optionally admin.',
                'VALIDATION_FAILED',
                422,
                ['roles' => ['Choose exactly one base role (employee, manager or hr_admin), and optionally admin.']],
            );
        }
    }

    /** @param  list<string>  $roles */
    public function baseRole(array $roles): string
    {
        $this->assertAllowedRoles($roles);
        foreach (self::BASE as $role) {
            if (in_array($role->value, $roles, true)) {
                return $role->value;
            }
        }

        throw new DomainException('Choose exactly one base role.', 'VALIDATION_FAILED', 422, ['roles' => ['Choose exactly one base role.']]);
    }

    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function lock(Closure $callback): mixed
    {
        return Cache::lock('lock:admin-guard', 10)->block(5, $callback);
    }
}
