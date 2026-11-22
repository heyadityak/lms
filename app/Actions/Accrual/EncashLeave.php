<?php

declare(strict_types=1);

namespace App\Actions\Accrual;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\LedgerKind;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveBalanceService;
use App\Support\LeaveYear;
use Illuminate\Support\Facades\DB;

/** HR encashment: a negative `encashment` ledger row. Available balance may not go below zero. */
final class EncashLeave
{
    public function __construct(private readonly LeaveBalanceService $balances, private readonly RecordAuditLog $audit) {}

    public function handle(User $user, LeaveType $type, float $days, string $note, User $actor, ?int $year = null): LeaveLedgerEntry
    {
        $year ??= LeaveYear::current();
        if (! $type->tracks_balance) {
            throw new LeaveRuleViolation('VALIDATION_FAILED', "{$type->code} does not track a balance.", 'leave_type_id');
        }
        if ($days <= 0) {
            throw new LeaveRuleViolation('VALIDATION_FAILED', 'Encashment days must be greater than zero.', 'days');
        }

        return DB::transaction(function () use ($user, $type, $days, $note, $actor, $year): LeaveLedgerEntry {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $available = $this->balances->available($user, $type, $year);
            if ($available - $days < 0) {
                throw new LeaveRuleViolation('INSUFFICIENT_BALANCE', "The {$type->code} available balance for {$year} is {$available}; encashment would go negative.", 'days');
            }

            $entry = LeaveLedgerEntry::query()->create([
                'user_id' => $user->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'kind' => LedgerKind::Encashment,
                'days' => -$days,
                'actor_id' => $actor->id,
                'note' => $note,
            ]);
            $this->audit->write($actor, 'encashment', $entry->public_id, 'created', [
                'before' => null,
                'after' => [
                    'user_id' => $user->public_id,
                    'leave_type_id' => $type->public_id,
                    'year' => $year,
                    'days' => -$days,
                    'note' => $note,
                ],
            ]);

            return $entry;
        });
    }
}
