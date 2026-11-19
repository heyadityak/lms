<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** openapi `Balance`, from one LeaveBalanceService::summary() row. */
final class BalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['leave_type' => LeaveTypeResource::summary($this->resource['leave_type'])] + $this->resource;
    }
}
