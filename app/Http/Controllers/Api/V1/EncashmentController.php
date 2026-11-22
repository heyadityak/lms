<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accrual\EncashLeave;
use App\Http\Requests\StoreEncashmentRequest;
use App\Http\Resources\LedgerEntryResource;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class EncashmentController
{
    public function store(StoreEncashmentRequest $request, User $user, EncashLeave $encash): JsonResponse
    {
        $entry = $encash->handle(
            $user,
            LeaveType::query()->where('public_id', $request->validated('leave_type_id'))->firstOrFail(),
            (float) $request->validated('days'),
            $request->validated('note'),
            $request->user(),
            $request->validated('year') === null ? null : (int) $request->validated('year'),
        );

        return (new LedgerEntryResource($entry->load(['leaveType', 'actor'])))->envelope(201);
    }
}
