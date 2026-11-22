<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerKind;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\HasPublicId;
use Database\Factories\LeaveLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only balance ledger (D-02): balance = SUM(days) per (user, type, year). */
class LeaveLedgerEntry extends Model
{
    /** @use HasFactory<LeaveLedgerEntryFactory> */
    use AppendOnly, HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'leave_type_id', 'year', 'accrual_month', 'kind', 'days', 'leave_request_id', 'actor_id', 'note'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'accrual_month' => 'integer',
            'kind' => LedgerKind::class,
            'days' => 'float',
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
