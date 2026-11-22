<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;

/** Shared checks for avatar and leave-attachment uploads. SVG is never accepted. */
final class UploadInspector
{
    public static function rejectSvg(UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        $snippet = strtolower(substr($file->getContent(), 0, 512));
        if ($mime === 'image/svg+xml' || $extension === 'svg' || str_contains($snippet, '<svg')) {
            throw new DomainException('SVG files are not allowed.', 'UNSUPPORTED_MEDIA_TYPE', 415);
        }
    }

    /** @param  list<string>  $allowed */
    public static function mime(UploadedFile $file, array $allowed): string
    {
        self::rejectSvg($file);
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $allowed, true)) {
            throw new DomainException('This file type is not allowed.', 'UNSUPPORTED_MEDIA_TYPE', 415);
        }

        return $mime;
    }

    public static function size(UploadedFile $file, int $maxBytes, string $label): void
    {
        if ($file->getSize() > $maxBytes) {
            throw new DomainException("The file must be at most {$label}.", 'VALIDATION_FAILED', 422, ['file' => ["The file must be at most {$label}."]]);
        }
    }

    public static function extension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
