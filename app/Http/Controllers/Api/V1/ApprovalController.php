<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leave\ApproveLeaveRequest;
use App\Actions\Leave\RejectLeaveRequest;
use App\Http\Requests\ApproveLeaveRequestRequest;
use App\Http\Requests\ListLeaveRequestsRequest;
use App\Http\Requests\RejectLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ApprovalController
{
    public function index(ListLeaveRequestsRequest $request): JsonResponse
    {
        Gate::authorize('viewApprovals', LeaveRequest::class);
        $query = LeaveRequestQuery::approvableBy($request->user());

        return LeaveRequestResource::page(LeaveRequestQuery::page($query, $request->filters(), $request->validated('sort'), $request->pageSize(), $request->pageNumber(), $request->includes('actions')), $request);
    }

    public function approve(ApproveLeaveRequestRequest $request, LeaveRequest $leaveRequest, ApproveLeaveRequest $approve): JsonResponse
    {
        return LeaveRequestController::resource($approve->handle($leaveRequest, $request->user(), $request->validated('comment')))->envelope();
    }

    public function reject(RejectLeaveRequestRequest $request, LeaveRequest $leaveRequest, RejectLeaveRequest $reject): JsonResponse
    {
        return LeaveRequestController::resource($reject->handle($leaveRequest, $request->user(), $request->validated('comment')))->envelope();
    }
}
