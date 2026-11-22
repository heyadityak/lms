<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\LeaveStatus;
use App\Models\LeaveAttachment;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Owner removes an attachment while the request is still pending, and deletes the stored object. */
final class DeleteLeaveAttachment
{
    public function handle(LeaveRequest $request, LeaveAttachment $attachment, User $actor): void
    {
        if ($attachment->leave_request_id !== $request->id) {
            abort(404);
        }

        DB::transaction(function () use ($request, $attachment, $actor): void {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->user_id !== $actor->id || $locked->status !== LeaveStatus::Pending) {
                throw new AuthorizationException('Only the requester can remove a file while the request is pending.');
            }

            $path = $attachment->path;
            $disk = $attachment->disk;
            $attachment->delete();
            Storage::disk($disk)->delete($path);
        });
    }
}
