<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use App\Models\LeaveLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** openapi `LedgerEntry`. @mixin LeaveLedgerEntry */
final class LedgerEntryResource extends JsonResource
{
    use Envelopes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'leave_type' => LeaveTypeResource::summary($this->leaveType),
            'year' => $this->year,
            'kind' => $this->kind->value,
            'days' => $this->days,
            'leave_request_id' => $this->leaveRequest?->public_id,
            'actor' => UserResource::summary($this->actor),
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
