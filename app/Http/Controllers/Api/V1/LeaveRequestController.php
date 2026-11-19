<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leave\CancelLeaveRequest;
use App\Actions\Leave\SubmitLeaveRequest;
use App\Http\Requests\CancelLeaveRequestRequest;
use App\Http\Requests\ListLeaveRequestsRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class LeaveRequestController
{
    public function index(ListLeaveRequestsRequest $request): JsonResponse
    {
        $query = LeaveRequestQuery::visibleTo($request->user());

        return LeaveRequestResource::page(LeaveRequestQuery::page($query, $request->filters(), $request->validated('sort'), $request->pageSize(), $request->pageNumber(), $request->includes('actions')), $request);
    }

    public function store(StoreLeaveRequestRequest $request, SubmitLeaveRequest $submit): JsonResponse
    {
        return self::resource($submit->handle($request->user(), $request->validated()), false)->envelope(201);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        Gate::authorize('view', $leaveRequest);
        $request->validate(['include' => ['sometimes', 'string', 'regex:/^(actions|user|leave_type)(,(actions|user|leave_type))*$/']]);

        return self::resource($leaveRequest, in_array('actions', explode(',', (string) $request->query('include')), true))->envelope();
    }

    public function cancel(CancelLeaveRequestRequest $request, LeaveRequest $leaveRequest, CancelLeaveRequest $cancel): JsonResponse
    {
        return self::resource($cancel->handle($leaveRequest, $request->user(), $request->validated('comment')))->envelope();
    }

    public static function resource(LeaveRequest $leaveRequest, bool $withActions = true): LeaveRequestResource
    {
        return new LeaveRequestResource($leaveRequest->load(array_merge(['user', 'leaveType', 'decider'], $withActions ? ['actions.actor'] : [])));
    }
}
