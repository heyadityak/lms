<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** openapi `User`; `id` is the ULID public_id (D-21). @mixin User */
final class UserResource extends JsonResource
{
    use Envelopes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'is_admin' => $this->is_admin,
            'department' => $this->department === null ? null : ['id' => $this->department->public_id, 'code' => $this->department->code, 'name' => $this->department->name],
            'location' => $this->location === null ? null : ['id' => $this->location->public_id, 'code' => $this->location->code, 'name' => $this->location->name],
            'avatar_url' => is_string($this->avatar_path) && $this->avatar_path !== '' ? route('api.v1.users.avatar', $this->resource) : null,
            'manager' => self::summary($this->manager),
            'employee_code' => $this->employee_code,
            'designation' => $this->designation,
            'employment_type' => $this->employment_type,
            'date_of_joining' => $this->date_of_joining?->toDateString(),
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /** openapi `UserSummary`. @return array{id: string, name: string}|null */
    public static function summary(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->public_id, 'name' => $user->name];
    }
}
