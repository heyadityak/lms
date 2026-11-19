<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\LeaveAction;
use App\Enums\LeaveStatus;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestSubmitted;
use App\Services\ApproverResolver;
use App\Services\LeaveRequestValidator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** UC-E5: rules V1–V8 via LeaveRequestValidator (shared with the preview), server-computed days. */
final class SubmitLeaveRequest
{
    public function __construct(private readonly LeaveRequestValidator $validator, private readonly ApproverResolver $approvers) {}

    /** @param  array{leave_type_id: string, start_date: string, end_date: string, day_part?: string|null, reason: string, handover_note?: string|null}  $data */
    public function handle(User $user, array $data): LeaveRequest
    {
        try {
            return DB::transaction(function () use ($user, $data): LeaveRequest {
                // Serialises one user's submissions so the overlap and balance checks below see each other.
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $checked = $this->validator->validate($user, $data);

                $request = new LeaveRequest([
                    'leave_type_id' => $checked['type']->id,
                    'start_date' => $checked['start']->toDateString(),
                    'end_date' => $checked['end']->toDateString(),
                    'day_part' => $checked['part'],
                    'reason' => $data['reason'],
                    'handover_note' => $data['handover_note'] ?? null,
                ]);
                $request->forceFill(['user_id' => $user->id, 'days' => $checked['days'], 'status' => LeaveStatus::Pending])->save();
                $request->actions()->create(['actor_id' => $user->id, 'action' => LeaveAction::Submitted, 'from_status' => null, 'to_status' => LeaveStatus::Pending]);

                LeaveTransition::notify($this->approvers->approversFor($user), new LeaveRequestSubmitted($request));

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
}
