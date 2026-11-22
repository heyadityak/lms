<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\RecordAuditLog;
use App\Exceptions\DomainException;
use App\Http\Requests\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use stdClass;

/** UC-H1. Delete only when no user (active or not) references it. Each write adds an audit row. */
final class DepartmentController
{
    public function index(): AnonymousResourceCollection
    {
        return DepartmentResource::collection(Department::query()->withCount('users')->with('leaveQuotas.leaveType')->orderBy('name')->get())
            ->additional(['meta' => new stdClass, 'links' => new stdClass]);
    }

    public function show(Department $department): JsonResponse
    {
        return (new DepartmentResource($department->loadCount('users')->load('leaveQuotas.leaveType')))->envelope();
    }

    public function store(DepartmentRequest $request, RecordAuditLog $audit): JsonResponse
    {
        $department = Department::query()->create($request->validated())->loadCount('users')->load('leaveQuotas.leaveType');
        $audit->write($request->user(), 'department', $department->public_id, 'created', $request->validated());

        return (new DepartmentResource($department))->envelope(201, route('api.v1.departments.show', $department));
    }

    public function update(DepartmentRequest $request, Department $department, RecordAuditLog $audit): JsonResponse
    {
        $department->update($request->validated());
        $audit->write($request->user(), 'department', $department->public_id, 'updated', $request->validated());

        return (new DepartmentResource($department->loadCount('users')->load('leaveQuotas.leaveType')))->envelope();
    }

    public function destroy(Department $department, RecordAuditLog $audit): Response
    {
        Gate::authorize('delete', $department);
        $notEmpty = new DomainException('The department still has users; move them first.', 'DEPARTMENT_NOT_EMPTY', 409);
        if ($department->users()->exists()) {
            throw $notEmpty;
        }

        try {
            $audit->write(request()->user(), 'department', $department->public_id, 'deleted', ['code' => $department->code, 'name' => $department->name]);
            $department->delete();
        } catch (QueryException $e) {
            // users.department_id is ON DELETE RESTRICT: a user assigned after the check above.
            throw $e->getCode() === '23503' ? $notEmpty : $e;
        }

        return response()->noContent();
    }
}
