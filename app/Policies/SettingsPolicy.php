<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Branding and modules are role `admin` (D-29). The working week is `hr_admin` (UC-H16, D-30). */
final class SettingsPolicy
{
    public function viewBranding(User $actor): bool
    {
        return true;
    }

    public function manageBranding(User $actor): bool
    {
        return $actor->is_admin === true;
    }

    public function manageModules(User $actor): bool
    {
        return $actor->is_admin === true;
    }

    public function viewWorkingWeek(User $actor): bool
    {
        return true;
    }

    public function updateWorkingWeek(User $actor): bool
    {
        return $actor->role === Role::HrAdmin;
    }
}
