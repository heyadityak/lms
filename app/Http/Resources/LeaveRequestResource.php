<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/** openapi `LeaveRequest`; `actions` only when loaded (include=actions). @mixin LeaveRequest */
final class LeaveRequestResource extends JsonResource
{
    use Envelopes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'user' => UserResource::summary($this->user),
            'leave_type' => LeaveTypeResource::summary($this->leaveType),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'day_part' => $this->day_part->value,
            'days' => $this->days,
            'hours' => $this->hours,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'handover_note' => $this->handover_note,
            'leave_year' => $this->leaveYear(),
            'is_taken' => $this->isTaken(),
            'decided_by' => UserResource::summary($this->decider),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_comment' => $this->decision_comment,
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->sortBy('id')->values()->map(fn (LeaveRequestAction $a): array => [
                'id' => $a->public_id,
                'action' => $a->action->value,
                'from_status' => $a->from_status?->value,
                'to_status' => $a->to_status->value,
                'comment' => $a->comment,
                'actor' => UserResource::summary($a->actor),
                'created_at' => $a->created_at->toIso8601ZuluString(),
            ])->all()),
        ];
    }

    /** Single-resource envelope with links.self (API_GUIDELINES §2). */
    public function envelope(int $status = 200): JsonResponse
    {
        $url = route('api.v1.leave-requests.show', $this->resource);

        return $this->additional(['meta' => new stdClass, 'links' => ['self' => $url]])
            ->response()->setStatusCode($status)
            ->withHeaders($status === 201 ? ['Location' => $url] : []);
    }
}
