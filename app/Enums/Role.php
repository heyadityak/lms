<?php

declare(strict_types=1);

namespace App\Enums;

/** Realm roles. `users.role` holds one base role; `Admin` is additive and mirrored to `users.is_admin` (D-29). */
enum Role: string
{
    case Employee = 'employee';
    case Manager = 'manager';
    case HrAdmin = 'hr_admin';
    case Admin = 'admin';

    /** Highest base role among Keycloak realm roles; null when none (login refused). */
    public static function fromKeycloakRoles(array $roles): ?self
    {
        foreach ([self::HrAdmin, self::Manager, self::Employee] as $role) {
            if (in_array($role->value, $roles, true)) {
                return $role;
            }
        }

        return null;
    }

    public function isBase(): bool
    {
        return $this !== self::Admin;
    }

    /** Base-role precedence (hr_admin > manager > employee); Admin is not ranked. */
    public function rank(): int
    {
        return match ($this) {
            self::Employee => 1,
            self::Manager => 2,
            self::HrAdmin => 3,
            self::Admin => 0,
        };
    }
}
