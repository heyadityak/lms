<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leave\StoreApprovalDelegation;
use App\Http\Requests\StoreApprovalDelegationRequest;
use App\Http\Resources\ApprovalDelegationResource;
use App\Models\ApprovalDelegation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** One delegation per caller. GET/DELETE have no id. */
final class ApprovalDelegationController
{
    public function show(): JsonResponse
    {
        Gate::authorize('viewAny', ApprovalDelegation::class);
        $delegation = ApprovalDelegation::query()->where('delegator_id', request()->user()->id)->with('delegate')->first();
        if ($delegation === null) {
            abort(404);
        }

        return (new ApprovalDelegationResource($delegation))->envelope();
    }

    public function store(StoreApprovalDelegationRequest $request, StoreApprovalDelegation $store): JsonResponse
    {
        $delegation = $store->handle($request->user(), $request->validated())->load('delegate');

        return (new ApprovalDelegationResource($delegation))->envelope(201, route('api.v1.me.approval-delegations.show'));
    }

    public function destroy(): Response
    {
        Gate::authorize('viewAny', ApprovalDelegation::class);
        $delegation = ApprovalDelegation::query()->where('delegator_id', request()->user()->id)->first();
        if ($delegation === null) {
            abort(404);
        }
        $delegation->delete();

        return response()->noContent();
    }
}
