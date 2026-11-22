<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeaveStatus;
use App\Http\Requests\TeamCalendarRequest;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use stdClass;

/** Approved leave in the caller's department. No reason. No department means only the caller's own leave. */
final class TeamCalendarController
{
    public function index(TeamCalendarRequest $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->validated('from');
        $to = $request->validated('to');

        $rows = LeaveRequest::query()
            ->where('status', LeaveStatus::Approved)
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->when(
                $user->department_id === null,
                fn ($query) => $query->where('user_id', $user->id),
                fn ($query) => $query->whereHas('user', fn ($people) => $people->where('department_id', $user->department_id)),
            )
            ->with(['user', 'leaveType'])
            ->get()
            ->sortBy(fn (LeaveRequest $leave): string => $leave->start_date->toDateString().' '.$leave->user->name)
            ->values()
            ->map(fn (LeaveRequest $leave): array => [
                'user_name' => $leave->user->name,
                'leave_type_name' => $leave->leaveType->name,
                'start_date' => $leave->start_date->toDateString(),
                'end_date' => $leave->end_date->toDateString(),
                'days' => $leave->days,
            ])
            ->all();

        return response()->json(['data' => $rows, 'meta' => new stdClass, 'links' => new stdClass]);
    }
}
