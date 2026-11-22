<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\LeaveStatus;
use App\Exceptions\LeaveRuleViolation;
use App\Models\LeaveAttachment;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\UploadInspector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Owner uploads a PNG, JPEG or PDF (max 5 MB) onto a pending request. Stored on the s3 disk. */
final class StoreLeaveAttachment
{
    public function handle(LeaveRequest $request, User $actor, UploadedFile $file): LeaveAttachment
    {
        $mime = UploadInspector::mime($file, ['image/png', 'image/jpeg', 'application/pdf']);
        UploadInspector::size($file, 5 * 1024 * 1024, '5 MB');

        return DB::transaction(function () use ($request, $actor, $file, $mime): LeaveAttachment {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->user_id !== $actor->id || $locked->status !== LeaveStatus::Pending) {
                throw new AuthorizationException('Only the requester can attach a file while the request is pending.');
            }
            if ($locked->attachments()->exists()) {
                throw new LeaveRuleViolation('VALIDATION_FAILED', 'This request already has an attachment. Delete it first.', 'file');
            }

            $publicId = (string) Str::ulid();
            $path = 'leave-attachments/'.$locked->public_id.'/'.$publicId.'.'.UploadInspector::extension($mime);
            Storage::disk('s3')->put($path, $file->getContent());

            $attachment = new LeaveAttachment([
                'leave_request_id' => $locked->id,
                'uploaded_by' => $actor->id,
                'disk' => 's3',
                'path' => $path,
                'original_name' => self::name($file),
                'mime_type' => $mime,
                'size' => $file->getSize() === false ? 0 : $file->getSize(),
            ]);
            $attachment->public_id = $publicId;
            $attachment->save();

            return $attachment;
        });
    }

    private static function name(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());

        return $name === '' ? 'upload' : mb_substr($name, 0, 255);
    }
}
