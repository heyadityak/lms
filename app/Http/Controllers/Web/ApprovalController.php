<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Leave\ApproveLeaveRequest;
use App\Actions\Leave\RejectLeaveRequest;
use App\Http\Requests\ApproveLeaveRequestRequest;
use App\Http\Requests\ListLeaveRequestsRequest;
use App\Http\Requests\RejectLeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** UC-M1, UC-M2, UC-M3, UC-H6 (web). */
final class ApprovalController
{
    public function index(ListLeaveRequestsRequest $request): View
    {
        Gate::authorize('viewApprovals', LeaveRequest::class);
        $query = LeaveRequestQuery::approvableBy($request->user());

        return view('approvals.index', [
            'requests' => LeaveRequestQuery::page($query, [], $request->validated('sort') ?? 'start_date', $request->pageSize(), $request->pageNumber()),
        ]);
    }

    public function approve(ApproveLeaveRequestRequest $request, LeaveRequest $leaveRequest, ApproveLeaveRequest $approve): RedirectResponse
    {
        $approve->handle($leaveRequest, $request->user(), $request->validated('comment'));

        return redirect()->route('approvals.index')->with('status', "Approved {$leaveRequest->user->name}'s request.");
    }

    public function reject(RejectLeaveRequestRequest $request, LeaveRequest $leaveRequest, RejectLeaveRequest $reject): RedirectResponse
    {
        $reject->handle($leaveRequest, $request->user(), $request->validated('comment'));

        return redirect()->route('approvals.index')->with('status', "Rejected {$leaveRequest->user->name}'s request.");
    }
}
