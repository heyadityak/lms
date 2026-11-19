<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** openapi `LeaveType`. @mixin LeaveType */
final class LeaveTypeResource extends JsonResource
{
    use Envelopes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'annual_quota' => $this->annual_quota,
            'tracks_balance' => $this->tracks_balance,
            'carry_forward_cap' => $this->carry_forward_cap,
            'max_balance' => $this->max_balance,
            'allow_half_day' => $this->allow_half_day,
            'max_backdate_days' => $this->max_backdate_days,
            'is_active' => $this->is_active,
        ];
    }

    /** openapi `LeaveTypeSummary`. @return array{id: string, code: string, name: string} */
    public static function summary(LeaveType $type): array
    {
        return ['id' => $type->public_id, 'code' => $type->code, 'name' => $type->name];
    }
}
