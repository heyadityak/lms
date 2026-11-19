<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Reads: any role (route x-roles). Writes: hr_admin only (USE_CASES §2.3). */
final class HolidayPolicy
{
    public function create(User $user): bool
    {
        return $user->role === Role::HrAdmin;
    }

    public function update(User $user): bool
    {
        return $user->role === Role::HrAdmin;
    }

    public function delete(User $user): bool
    {
        return $user->role === Role::HrAdmin;
    }
}
