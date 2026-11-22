<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;

/** Reads: any role. Writes: hr_admin. */
final class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Location $location): bool
    {
        return $user->is_active;
    }

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
