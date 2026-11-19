<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\DayPart;
use App\Enums\LeaveAction;
use App\Enums\LeaveStatus;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ApproverResolver;
use App\Services\LeaveBalanceService;
use App\Services\WorkingDayCalculator;
use App\Support\LeaveYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** UC-E5: validation V1–V8 (USE_CASES §3.4, V9 shapes are the Form Request's), server-computed days. */
final class SubmitLeaveRequest
{
    public function __construct(
        private readonly WorkingDayCalculator $calculator,
        private readonly LeaveBalanceService $balances,
        private readonly ApproverResolver $approvers,
    ) {}

    /** @param  array{leave_type_id: string, start_date: string, end_date: string, day_part?: string|null, reason: string, handover_note?: string|null}  $data */
    public function handle(User $user, array $data): LeaveRequest
    {
        try {
            return DB::transaction(function () use ($user, $data): LeaveRequest {
                // Serialises one user's submissions so the overlap and balance checks below see each other.
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $type = LeaveType::query()->where('public_id', $data['leave_type_id'])->firstOrFail();
                $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
                $end = CarbonImmutable::parse($data['end_date'])->startOfDay();
                $part = DayPart::from($data['day_part'] ?? DayPart::Full->value);
                $today = CarbonImmutable::today();

                if (! $type->is_active) {
                    throw new LeaveRuleViolation('LEAVE_TYPE_INACTIVE', 'This leave type is no longer available.', 'leave_type_id');
                }
                if ($start->lt($today->subDays($type->max_backdate_days))) {
                    throw new LeaveRuleViolation('BACKDATE_LIMIT_EXCEEDED', "{$type->code} can be backdated by at most {$type->max_backdate_days} days.", 'start_date');
                }
                if ($end->lt($start)) {
                    throw new LeaveRuleViolation('INVALID_DATE_RANGE', 'The end date must be on or after the start date.', 'end_date');
                }
                $year = LeaveYear::of($start);
                if (LeaveYear::of($end) !== $year) {
                    throw new LeaveRuleViolation('INVALID_DATE_RANGE', 'A request cannot span two leave years; leave year '.$year.' ends on '.LeaveYear::end($year)->toDateString().'. Submit two requests.', 'end_date');
                }
                if ($part->isHalf() && (! $start->equalTo($end) || ! $type->allow_half_day)) {
                    throw new LeaveRuleViolation('HALF_DAY_NOT_ALLOWED', $type->allow_half_day ? 'A half day must start and end on the same date.' : "{$type->code} cannot be taken as a half day.", 'day_part');
                }
                $days = $this->calculator->forRange($start, $end, $part);
                if ($days <= 0) {
                    throw new LeaveRuleViolation('NO_WORKING_DAYS', 'Selected dates contain no working days.', 'start_date');
                }
                self::assertNoOverlap($user, $start, $end);
                if ($type->tracks_balance && $days > ($available = $this->balances->available($user, $type, $year))) {
                    throw new LeaveRuleViolation('INSUFFICIENT_BALANCE', "Requested {$days} days of {$type->code} but only {$available} are available.", 'end_date');
                }
                if ($this->approvers->approversFor($user)->isEmpty()) {
                    throw new LeaveRuleViolation('NO_APPROVER', 'No approver configured. Ask HR to assign a manager.', 'leave_type_id');
                }

                $request = new LeaveRequest([
                    'leave_type_id' => $type->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'day_part' => $part,
                    'reason' => $data['reason'],
                    'handover_note' => $data['handover_note'] ?? null,
                ]);
                $request->forceFill(['user_id' => $user->id, 'days' => $days, 'status' => LeaveStatus::Pending])->save();
                $request->actions()->create(['actor_id' => $user->id, 'action' => LeaveAction::Submitted, 'from_status' => null, 'to_status' => LeaveStatus::Pending]);

                // shortcut: LeaveRequestSubmitted notification is phase 8 (module `notifications`), dispatch afterCommit there.
                return $request;
            });
        } catch (QueryException $e) {
            // Backstop for a race the pre-check missed: leave_requests_no_overlap EXCLUDE constraint.
            if ($e->getCode() === '23P01') {
                throw new LeaveRuleViolation('LEAVE_OVERLAP', 'You already have a pending or approved request on these dates.', 'start_date');
            }

            throw $e;
        }
    }

    /** V6: no other pending/approved request of the same user intersecting [start, end] (inclusive, half-days too). */
    public static function assertNoOverlap(User $user, CarbonImmutable $start, CarbonImmutable $end, ?int $exceptRequestId = null): void
    {
        $other = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->when($exceptRequestId !== null, fn ($q) => $q->whereKeyNot($exceptRequestId))
            ->first();

        if ($other !== null) {
            throw new LeaveRuleViolation('LEAVE_OVERLAP', "You already have a pending or approved request between {$other->start_date->toDateString()} and {$other->end_date->toDateString()}.", 'start_date');
        }
    }
}
