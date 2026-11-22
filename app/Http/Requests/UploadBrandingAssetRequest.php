<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\DomainException;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/** PUT /settings/branding/assets/{logo|favicon}. SVG is 415; size and pixel limits are 422. */
final class UploadBrandingAssetRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    public function authorize(): bool
    {
        return $this->user()->can('manageBranding', Setting::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');
                if (! $file instanceof UploadedFile || $validator->errors()->isNotEmpty()) {
                    return;
                }
                $this->assertAcceptable($file, (string) $this->route('asset'));
            },
            ...$this->rejectUnknownFields(),
        ];
    }

    private function assertAcceptable(UploadedFile $file, string $asset): void
    {
        $mime = (string) $file->getMimeType();
        $snippet = strtolower(substr($file->getContent(), 0, 512));
        $extension = strtolower($file->getClientOriginalExtension());
        if ($mime === 'image/svg+xml' || $extension === 'svg' || str_contains($snippet, '<svg')) {
            throw new DomainException('SVG images are not allowed.', 'UNSUPPORTED_MEDIA_TYPE', 415);
        }

        $allowed = $asset === 'favicon'
            ? ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon']
            : ['image/png', 'image/jpeg', 'image/webp'];
        if (! in_array($mime, $allowed, true)) {
            throw new DomainException('This image type is not allowed.', 'UNSUPPORTED_MEDIA_TYPE', 415);
        }

        $maxBytes = $asset === 'favicon' ? 256 * 1024 : 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            $limit = $asset === 'favicon' ? '256 KB' : '1 MB';
            throw new DomainException("The file must be at most {$limit}.", 'VALIDATION_FAILED', 422, ['file' => ["The file must be at most {$limit}."]]);
        }

        if ($asset === 'logo') {
            $size = @getimagesize($file->getPathname());
            if ($size === false || $size[0] > 1024 || $size[1] > 1024) {
                throw new DomainException(
                    'The logo must be at most 1024 by 1024 pixels.',
                    'VALIDATION_FAILED',
                    422,
                    ['file' => ['The logo must be at most 1024 by 1024 pixels.']],
                );
            }
        }
    }
}
