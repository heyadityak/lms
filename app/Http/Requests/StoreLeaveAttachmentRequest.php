<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use App\Support\UploadInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/** PNG, JPEG or PDF up to 5 MB. SVG is 415. */
final class StoreLeaveAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest && $this->user()->can('attach', $leaveRequest);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $file = $this->file('file');
            if (! $file instanceof UploadedFile || $validator->errors()->isNotEmpty()) {
                return;
            }
            UploadInspector::mime($file, ['image/png', 'image/jpeg', 'application/pdf']);
            UploadInspector::size($file, 5 * 1024 * 1024, '5 MB');
        }];
    }
}
