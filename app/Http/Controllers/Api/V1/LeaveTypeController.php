<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use stdClass;

/** Read side only; CRUD (UC-H3) is phase 8. */
final class LeaveTypeController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $active = $request->validate(['filter' => ['sometimes', 'array:is_active'], 'filter.is_active' => ['sometimes', 'in:true,false,1,0']])['filter']['is_active'] ?? 'true';
        // Only hr_admin may list inactive types.
        $active = $request->user()->role !== Role::HrAdmin || in_array($active, ['true', '1'], true);

        return LeaveTypeResource::collection(LeaveType::query()->where('is_active', $active)->orderBy('code')->get())
            ->additional(['meta' => new stdClass, 'links' => new stdClass]);
    }
}
