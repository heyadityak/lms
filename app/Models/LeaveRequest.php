<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DayPart;
use App\Enums\LeaveStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\LeaveYear;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** State changes only through App\Actions\Leave\* (status, decided_*, cancelled_at are not fillable). */
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = ['leave_type_id', 'start_date', 'end_date', 'day_part', 'reason', 'handover_note'];

    protected $attributes = [
        'day_part' => 'full',
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'day_part' => DayPart::class,
            'days' => 'float',
            'hours' => 'integer',
            'status' => LeaveStatus::class,
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<LeaveRequestAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(LeaveRequestAction::class);
    }

    /** @return HasMany<LeaveLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /** @return HasMany<LeaveAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveAttachment::class);
    }

    /** Derived, never stored (D-07): approved and already started. */
    public function isTaken(): bool
    {
        return $this->status === LeaveStatus::Approved && $this->start_date->toDateString() <= now()->toDateString();
    }

    public function leaveYear(): int
    {
        return LeaveYear::of($this->start_date);
    }
}
