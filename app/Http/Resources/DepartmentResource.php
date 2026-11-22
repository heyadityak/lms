<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** openapi `Department`; expects `users_count` (withCount). @mixin Department */
final class DepartmentResource extends JsonResource
{
    use Envelopes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'users_count' => (int) $this->users_count,
            'leave_quotas' => $this->leaveQuotas->map(fn ($quota): array => [
                'leave_type_id' => $quota->leaveType->public_id,
                'annual_quota' => $quota->annual_quota,
            ])->values()->all(),
        ];
    }
}
