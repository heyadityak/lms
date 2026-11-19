<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\LeaveAction;
use App\Enums\LeaveStatus;
use App\Enums\LedgerKind;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * UC-E7 / UC-H6: pending|approved → cancelled. Cancelling an approved request writes a `reversal` for the full debit.
 * Who may cancel when (owner before start, HR any time) = LeaveRequestPolicy::cancel.
 */
final class CancelLeaveRequest
{
    public function handle(LeaveRequest $request, User $actor, ?string $comment = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $comment): LeaveRequest {
            $locked = LeaveTransition::lock($request, LeaveStatus::Cancelled);

            $debit = $locked->status === LeaveStatus::Approved
                ? $locked->ledgerEntries()->where('kind', LedgerKind::Debit)->first()
                : null;
            if ($debit !== null) {
                $locked->ledgerEntries()->create([
                    'user_id' => $locked->user_id, 'leave_type_id' => $debit->leave_type_id, 'year' => $debit->year,
                    'kind' => LedgerKind::Reversal, 'days' => -$debit->days, 'actor_id' => $actor->id,
                ]);
            }

            return LeaveTransition::apply($locked, LeaveStatus::Cancelled, LeaveAction::Cancelled, $actor, $comment, ['cancelled_at' => now()]);
        });
    }
}
