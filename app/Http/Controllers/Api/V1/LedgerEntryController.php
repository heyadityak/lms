<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accrual\AdjustBalance;
use App\Http\Requests\ListLedgerEntriesRequest;
use App\Http\Requests\StoreAdjustmentRequest;
use App\Http\Resources\LedgerEntryResource;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LeaveYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Append-only ledger history (USE_CASES §3.5) and UC-H5 manual adjustments. */
final class LedgerEntryController
{
    public function mine(ListLedgerEntriesRequest $request): JsonResponse
    {
        return self::list($request, $request->user());
    }

    public function index(ListLedgerEntriesRequest $request, User $user): JsonResponse
    {
        Gate::authorize('viewBalances', $user);

        return self::list($request, $user);
    }

    public function store(StoreAdjustmentRequest $request, User $user, AdjustBalance $adjust): JsonResponse
    {
        $entry = $adjust->handle(
            $user,
            LeaveType::query()->where('public_id', $request->validated('leave_type_id'))->firstOrFail(),
            (float) $request->validated('days'),
            $request->validated('note'),
            $request->user(),
            $request->validated('year') === null ? null : (int) $request->validated('year'),
        );

        return (new LedgerEntryResource($entry->load(['leaveType', 'actor'])))->envelope(201, route('api.v1.users.ledger-entries.index', $user));
    }

    private static function list(ListLedgerEntriesRequest $request, User $user): JsonResponse
    {
        $filter = (array) $request->validated('filter', []);
        $query = $user->ledgerEntries()->getQuery()
            ->where('year', (int) ($filter['year'] ?? LeaveYear::current()))
            ->when(isset($filter['leave_type']), fn (Builder $q) => $q->whereHas('leaveType', fn (Builder $t) => $t->where('public_id', $filter['leave_type'])))
            ->with(['leaveType', 'leaveRequest', 'actor'])
            ->orderByDesc('id');

        return LedgerEntryResource::page($query->paginate((int) $request->validated('page.size', 25), ['*'], 'page[number]', (int) $request->validated('page.number', 1)), $request);
    }
}
