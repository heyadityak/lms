<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Envelopes;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** openapi `Holiday`. @mixin Holiday */
final class HolidayResource extends JsonResource
{
    use Envelopes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->public_id, 'date' => $this->date->toDateString(), 'name' => $this->name];
    }
}
