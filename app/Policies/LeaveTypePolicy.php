<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Reads: any role. Writes: hr_admin only (UC-H3). No delete: deactivate via is_active. */
final class LeaveTypePolicy
{
    public function create(User $user): bool
    {
        return $user->role === Role::HrAdmin;
    }

    public function update(User $user): bool
    {
        return $user->role === Role::HrAdmin;
    }
}
