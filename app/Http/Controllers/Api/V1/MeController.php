<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ListLeaveRequestsRequest;
use App\Http\Resources\BalanceResource;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Resources\UserResource;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveBalanceService;
use App\Services\LeaveRequestQuery;
use App\Support\LeaveYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use stdClass;

final class MeController
{
    public function show(Request $request): JsonResponse
    {
        // Explicit 200: JsonResource answers 201 for a model created in this request (first-call provisioning).
        return (new UserResource($request->user()->load('department', 'manager')))->additional(['meta' => new stdClass, 'links' => new stdClass])
            ->response()->setStatusCode(200);
    }

    public function balances(Request $request, LeaveBalanceService $balances): AnonymousResourceCollection
    {
        return self::balanceList($request, $request->user(), $balances);
    }

    /** openapi `BalanceList` for /me/balances and /users/{id}/balances. */
    public static function balanceList(Request $request, User $user, LeaveBalanceService $balances): AnonymousResourceCollection
    {
        $year = (int) ($request->validate(['filter' => ['sometimes', 'array:year'], 'filter.year' => ['sometimes', 'integer', 'between:2000,2100']])['filter']['year'] ?? LeaveYear::current());

        return BalanceResource::collection($balances->summary($user, $year))->additional([
            'meta' => ['year' => $year, 'leave_year_start' => LeaveYear::start($year)->toDateString(), 'leave_year_end' => LeaveYear::end($year)->toDateString()],
            'links' => new stdClass,
        ]);
    }

    public function leaveRequests(ListLeaveRequestsRequest $request): JsonResponse
    {
        $query = LeaveRequest::query()->where('user_id', $request->user()->id);

        return LeaveRequestResource::page(LeaveRequestQuery::page($query, $request->filters(), $request->validated('sort'), $request->pageSize(), $request->pageNumber(), $request->includes('actions')), $request);
    }
}
