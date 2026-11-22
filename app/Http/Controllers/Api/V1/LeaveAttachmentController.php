<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leave\DeleteLeaveAttachment;
use App\Actions\Leave\StoreLeaveAttachment;
use App\Http\Requests\StoreLeaveAttachmentRequest;
use App\Http\Resources\LeaveAttachmentResource;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One attachment per leave request. GET streams it. DELETE takes no id. */
final class LeaveAttachmentController
{
    public function show(LeaveRequest $leaveRequest): StreamedResponse
    {
        Gate::authorize('readDocument', $leaveRequest);
        $attachment = $leaveRequest->attachments()->first();
        if ($attachment === null) {
            abort(404);
        }

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name);
    }

    public function store(StoreLeaveAttachmentRequest $request, LeaveRequest $leaveRequest, StoreLeaveAttachment $store): JsonResponse
    {
        $attachment = $store->handle($leaveRequest, $request->user(), $request->file('file'));

        return (new LeaveAttachmentResource($attachment))->envelope(201);
    }

    public function destroy(LeaveRequest $leaveRequest, DeleteLeaveAttachment $delete): Response
    {
        Gate::authorize('attach', $leaveRequest);
        $attachment = $leaveRequest->attachments()->first();
        if ($attachment === null) {
            abort(404);
        }
        $delete->handle($leaveRequest, $attachment, request()->user());

        return response()->noContent();
    }
}
