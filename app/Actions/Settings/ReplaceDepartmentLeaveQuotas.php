<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Actions\Audit\RecordAuditLog;
use App\Models\Department;
use App\Models\DepartmentLeaveQuota;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Upsert one department quota, or delete it when the quota is null. */
final class ReplaceDepartmentLeaveQuotas
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(Department $department, LeaveType $type, ?float $annualQuota, User $actor): ?DepartmentLeaveQuota
    {
        return DB::transaction(function () use ($department, $type, $annualQuota, $actor): ?DepartmentLeaveQuota {
            $existing = DepartmentLeaveQuota::query()
                ->where('department_id', $department->id)
                ->where('leave_type_id', $type->id)
                ->first();
            $before = $existing === null ? null : ['leave_type_id' => $type->public_id, 'annual_quota' => $existing->annual_quota];

            if ($annualQuota === null) {
                $existing?->delete();
                $this->audit->write($actor, 'leave_quota', $department->public_id, 'deleted', [
                    'before' => $before,
                    'after' => null,
                ]);

                return null;
            }

            $quota = DepartmentLeaveQuota::query()->updateOrCreate(
                ['department_id' => $department->id, 'leave_type_id' => $type->id],
                ['annual_quota' => $annualQuota],
            );
            $this->audit->write($actor, 'leave_quota', $department->public_id, $existing === null ? 'created' : 'updated', [
                'before' => $before,
                'after' => ['leave_type_id' => $type->public_id, 'annual_quota' => $annualQuota],
            ]);

            return $quota;
        });
    }
}
