<?php

declare(strict_types=1);

namespace App\Actions\Accrual;

use App\Enums\LedgerKind;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveBalanceService;
use App\Support\LeaveYear;
use Illuminate\Support\Facades\DB;

/** UC-H5: signed `adjustment` ledger row with a note. The balance may not go below zero (USE_CASES §3.4 V7). */
final class AdjustBalance
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function handle(User $user, LeaveType $type, float $days, string $note, User $actor, ?int $year = null): LeaveLedgerEntry
    {
        $year ??= LeaveYear::current();
        if (! $type->tracks_balance) {
            throw new LeaveRuleViolation('VALIDATION_FAILED', "{$type->code} does not track a balance.", 'leave_type_id');
        }

        return DB::transaction(function () use ($user, $type, $days, $note, $actor, $year): LeaveLedgerEntry {
            // Same lock as SubmitLeaveRequest: serialises balance writes for this user.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $balance = $this->balances->balance($user, $type, $year);
            if ($balance + $days < 0) {
                throw new LeaveRuleViolation('INSUFFICIENT_BALANCE', "The {$type->code} balance for {$year} is {$balance}; it cannot go below zero.", 'days');
            }

            return LeaveLedgerEntry::query()->create([
                'user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year,
                'kind' => LedgerKind::Adjustment, 'days' => $days, 'actor_id' => $actor->id, 'note' => $note,
            ]);
        });
    }
}
