<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\LeaveAction;
use App\Enums\LeaveStatus;
use App\Enums\LedgerKind;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveBalanceService;
use App\Services\LeaveRequestValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** UC-M2 / UC-H6: pending → approved, re-check V6 + V7, `debit` ledger row. Who may approve = LeaveRequestPolicy::decide. */
final class ApproveLeaveRequest
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function handle(LeaveRequest $request, User $actor, ?string $comment = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $comment): LeaveRequest {
            $locked = LeaveTransition::lock($request, LeaveStatus::Approved);
            if ($locked->user_id === $actor->id) {
                throw new AuthorizationException('You cannot approve your own leave request.');
            }

            $type = $locked->leaveType;
            $year = $locked->leaveYear();
            LeaveRequestValidator::assertNoOverlap($locked->user, $locked->start_date, $locked->end_date, $locked->id);
            if ($type->tracks_balance) {
                $available = $this->balances->available($locked->user, $type, $year, $locked->id);
                if ($locked->days > $available) {
                    throw new LeaveRuleViolation('INSUFFICIENT_BALANCE', "Requested {$locked->days} days of {$type->code} but only {$available} are available.", 'days');
                }
                $locked->ledgerEntries()->create([
                    'user_id' => $locked->user_id, 'leave_type_id' => $type->id, 'year' => $year,
                    'kind' => LedgerKind::Debit, 'days' => -$locked->days, 'actor_id' => $actor->id,
                ]);
            }

            return LeaveTransition::apply($locked, LeaveStatus::Approved, LeaveAction::Approved, $actor, $comment, [
                'decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment,
            ]);
        });
    }
}
