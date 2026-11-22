<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DepartmentLeaveQuota;
use App\Models\LeaveType;
use App\Models\User;

/** Department override of a leave type's annual quota, else the type's own quota. */
final class DepartmentQuota
{
    public static function for(User $user, LeaveType $type): float
    {
        if ($user->department_id === null) {
            return (float) $type->annual_quota;
        }

        $override = DepartmentLeaveQuota::query()
            ->where('department_id', $user->department_id)
            ->where('leave_type_id', $type->id)
            ->value('annual_quota');

        return $override === null ? (float) $type->annual_quota : (float) $override;
    }
}
