<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use App\Models\ApprovalDelegation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalDelegation */
final class ApprovalDelegationResource extends JsonResource
{
    use Envelopes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'delegate' => UserResource::summary($this->delegate),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
        ];
    }
}
