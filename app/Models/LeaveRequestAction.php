<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeaveAction;
use App\Enums\LeaveStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\LeaveRequestActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit trail of request transitions (one row per transition). */
class LeaveRequestAction extends Model
{
    /** @use HasFactory<LeaveRequestActionFactory> */
    use HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = ['leave_request_id', 'actor_id', 'action', 'from_status', 'to_status', 'comment'];

    protected function casts(): array
    {
        return [
            'action' => LeaveAction::class,
            'from_status' => LeaveStatus::class,
            'to_status' => LeaveStatus::class,
        ];
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
