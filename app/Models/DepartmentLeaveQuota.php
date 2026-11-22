<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\DepartmentLeaveQuotaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentLeaveQuota extends Model
{
    /** @use HasFactory<DepartmentLeaveQuotaFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = ['department_id', 'leave_type_id', 'annual_quota'];

    protected function casts(): array
    {
        return ['annual_quota' => 'float'];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
