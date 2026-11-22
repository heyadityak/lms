<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\ApprovalDelegation;
use App\Models\User;

/** A manager or hr_admin delegates their own approval window. */
final class ApprovalDelegationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, [Role::Manager, Role::HrAdmin], true);
    }

    public function create(User $user): bool
    {
        return $user->is_active && in_array($user->role, [Role::Manager, Role::HrAdmin], true);
    }

    public function delete(User $user, ApprovalDelegation $delegation): bool
    {
        return $user->id === $delegation->delegator_id;
    }
}
