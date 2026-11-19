<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Leave\CancelLeaveRequest;
use App\Actions\Leave\SubmitLeaveRequest;
use App\Enums\DayPart;
use App\Http\Requests\CancelLeaveRequestRequest;
use App\Http\Requests\ListLeaveRequestsRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveRequestQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** UC-E5, UC-E6, UC-E7 (web). Same Actions as the API. */
final class LeaveRequestController
{
    public function index(ListLeaveRequestsRequest $request): View
    {
        $query = LeaveRequest::query()->where('user_id', $request->user()->id);

        return view('leave-requests.index', [
            'requests' => LeaveRequestQuery::page($query, $request->filters(), $request->validated('sort'), $request->pageSize(), $request->pageNumber()),
            'status' => $request->filters()['status'] ?? '',
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', LeaveRequest::class);

        return view('leave-requests.create', [
            'types' => LeaveType::query()->where('is_active', true)->orderBy('code')->get(),
            'dayParts' => DayPart::cases(),
        ]);
    }

    public function store(StoreLeaveRequestRequest $request, SubmitLeaveRequest $submit): RedirectResponse
    {
        $leaveRequest = $submit->handle($request->user(), $request->validated());

        return redirect()->route('leave-requests.show', $leaveRequest)->with('status', "Leave request submitted ({$leaveRequest->days} days).");
    }

    public function show(LeaveRequest $leaveRequest): View
    {
        Gate::authorize('view', $leaveRequest);

        return view('leave-requests.show', ['request' => $leaveRequest->load('user', 'leaveType', 'decider', 'actions.actor')]);
    }

    public function cancel(CancelLeaveRequestRequest $request, LeaveRequest $leaveRequest, CancelLeaveRequest $cancel): RedirectResponse
    {
        $cancel->handle($leaveRequest, $request->user(), $request->validated('comment'));

        return back()->with('status', 'Leave request cancelled.');
    }
}
