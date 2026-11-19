<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

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

/** UC-H1. Plain CRUD (no side effects), so no Action class; delete only when no user (active or not) references it. */
final class DepartmentController
{
    public function index(): AnonymousResourceCollection
    {
        return DepartmentResource::collection(Department::query()->withCount('users')->orderBy('name')->get())
            ->additional(['meta' => new stdClass, 'links' => new stdClass]);
    }

    public function show(Department $department): JsonResponse
    {
        return (new DepartmentResource($department->loadCount('users')))->envelope();
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $department = Department::query()->create($request->validated())->loadCount('users');

        return (new DepartmentResource($department))->envelope(201, route('api.v1.departments.show', $department));
    }

    public function update(DepartmentRequest $request, Department $department): JsonResponse
    {
        $department->update($request->validated());

        return (new DepartmentResource($department->loadCount('users')))->envelope();
    }

    public function destroy(Department $department): Response
    {
        Gate::authorize('delete', $department);
        $notEmpty = new DomainException('The department still has users; move them first.', 'DEPARTMENT_NOT_EMPTY', 409);
        if ($department->users()->exists()) {
            throw $notEmpty;
        }

        try {
            $department->delete();
        } catch (QueryException $e) {
            // users.department_id is ON DELETE RESTRICT: a user assigned after the check above.
            throw $e->getCode() === '23503' ? $notEmpty : $e;
        }

        return response()->noContent();
    }
}
