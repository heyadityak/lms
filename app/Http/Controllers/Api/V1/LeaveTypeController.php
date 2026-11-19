<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Requests\LeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use stdClass;

/** UC-H3. Code immutable after create; no delete, deactivate with `is_active=false`. Quota changes affect future grants only. */
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

    public function show(LeaveType $leaveType): JsonResponse
    {
        return (new LeaveTypeResource($leaveType))->envelope();
    }

    public function store(LeaveTypeRequest $request): JsonResponse
    {
        $type = LeaveType::query()->create($request->validated());

        return (new LeaveTypeResource($type->refresh()))->envelope(201, route('api.v1.leave-types.show', $type));
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $leaveType->update($request->validated());

        return (new LeaveTypeResource($leaveType))->envelope();
    }
}
