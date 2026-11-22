<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\UploadInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/** PNG, JPEG or WebP up to 1 MB. SVG is 415. */
final class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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
            UploadInspector::mime($file, ['image/png', 'image/jpeg', 'image/webp']);
            UploadInspector::size($file, 1024 * 1024, '1 MB');
        }];
    }
}
