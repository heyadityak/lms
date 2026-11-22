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
use App\Services\LeaveRequestValidator;
use App\Support\Brand;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

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

    /** D-43 dry run: same body, auth and V1–V9 as store, writes nothing. */
    public function preview(StoreLeaveRequestRequest $request, LeaveRequestValidator $validator): JsonResponse
    {
        $checked = $validator->validate($request->user(), $request->validated());
        $available = $checked['available'];

        return response()->json(['data' => [
            'days' => $checked['days'],
            'leave_year' => $checked['year'],
            'tracks_balance' => $available !== null,
            'available_before' => $available,
            'available_after' => $available === null ? null : $available - $checked['days'],
        ], 'meta' => new stdClass, 'links' => new stdClass]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        Gate::authorize('view', $leaveRequest);
        $request->validate(['include' => ['sometimes', 'string', 'regex:/^(actions|user|leave_type)(,(actions|user|leave_type))*$/']]);

        return self::resource($leaveRequest, in_array('actions', explode(',', (string) $request->query('include')), true))->envelope();
    }

    public function pdf(LeaveRequest $leaveRequest): Response
    {
        Gate::authorize('readDocument', $leaveRequest);
        $leaveRequest->load(['user', 'leaveType']);
        $binary = Pdf::loadView('pdf.leave-request', [
            'brandName' => Brand::fromSettings(app(Settings::class))->name,
            'leaveRequest' => $leaveRequest,
        ])->output();

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="leave-request-'.$leaveRequest->public_id.'.pdf"',
        ]);
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
