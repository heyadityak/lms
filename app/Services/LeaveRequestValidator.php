<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DayPart;
use App\Enums\LeaveStatus;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LeaveYear;
use Carbon\CarbonImmutable;

/**
 * UC-E5 rules V1–V8 (USE_CASES §3.4; V9 shapes are the Form Request's), shared by SubmitLeaveRequest
 * (inside its user lock) and the preview endpoint (no lock, D-43). Read-only.
 */
final class LeaveRequestValidator
{
    public function __construct(
        private readonly WorkingDayCalculator $calculator,
        private readonly LeaveBalanceService $balances,
        private readonly ApproverResolver $approvers,
    ) {}

    /**
     * @param  array{leave_type_id: string, start_date: string, end_date: string, day_part?: string|null, hours?: int|null}  $data
     * @return array{type: LeaveType, start: CarbonImmutable, end: CarbonImmutable, part: DayPart, hours: ?int, days: float, year: int, available: ?float}
     */
    public function validate(User $user, array $data): array
    {
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
        $hours = isset($data['hours']) && $data['hours'] !== null ? (int) $data['hours'] : null;
        $sandwich = (bool) $type->sandwich;
        if ($hours !== null) {
            if ($part->isHalf()) {
                throw new LeaveRuleViolation('HALF_DAY_NOT_ALLOWED', 'Hours cannot be combined with a half day.', 'hours');
            }
            if (! $start->equalTo($end)) {
                throw new LeaveRuleViolation('INVALID_DATE_RANGE', 'Hourly leave must start and end on the same date.', 'hours');
            }
            if ($this->calculator->forRange($start, $end, DayPart::Full, $user->location_id, $sandwich) <= 0) {
                throw new LeaveRuleViolation('NO_WORKING_DAYS', 'Selected dates contain no working days.', 'start_date');
            }
            $days = $hours / 8;
        } else {
            $days = $this->calculator->forRange($start, $end, $part, $user->location_id, $sandwich);
        }
        if ($days <= 0) {
            throw new LeaveRuleViolation('NO_WORKING_DAYS', 'Selected dates contain no working days.', 'start_date');
        }
        self::assertNoOverlap($user, $start, $end);
        $available = $type->tracks_balance ? $this->balances->available($user, $type, $year) : null;
        if ($available !== null && $days > $available) {
            throw new LeaveRuleViolation('INSUFFICIENT_BALANCE', "Requested {$days} days of {$type->code} but only {$available} are available.", 'end_date');
        }
        if ($this->approvers->approversFor($user, $days)->isEmpty()) {
            throw new LeaveRuleViolation('NO_APPROVER', 'No approver configured. Ask HR to assign a manager.', 'leave_type_id');
        }

        return compact('type', 'start', 'end', 'part', 'hours', 'days', 'year', 'available');
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
