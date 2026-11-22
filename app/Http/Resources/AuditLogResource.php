<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
final class AuditLogResource extends JsonResource
{
    use Envelopes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'actor' => UserResource::summary($this->actor),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'action' => $this->action,
            'changes' => $this->changes,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
