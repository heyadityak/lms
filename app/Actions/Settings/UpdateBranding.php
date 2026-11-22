<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Support\BrandColor;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** UC-B1–B4. Name and colours, plus logo/favicon on BRANDING_DISK as `{asset}-{sha1}.{ext}`. */
final class UpdateBranding
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array{name?: string, primary_color?: string, accent_color?: string}  $data
     */
    public function handle(array $data, ?UploadedFile $logo, ?UploadedFile $favicon, User $actor): void
    {
        foreach (['primary_color', 'accent_color'] as $color) {
            if (isset($data[$color]) && ! BrandColor::isValidHex($data[$color])) {
                throw new DomainException('Colours must be #RRGGBB.', 'VALIDATION_FAILED', 422, [$color => ['Use a #RRGGBB colour.']]);
            }
        }

        $stored = [];
        if ($logo !== null) {
            $stored['brand.logo_path'] = $this->store('logo', $logo);
        }
        if ($favicon !== null) {
            $stored['brand.favicon_path'] = $this->store('favicon', $favicon);
        }

        $previous = [];
        foreach (array_keys($stored) as $key) {
            if ($this->settings->isOverridden($key)) {
                $path = $this->settings->get($key);
                if (is_string($path) && $path !== '' && $path !== $stored[$key]) {
                    $previous[] = $path;
                }
            }
        }

        DB::transaction(function () use ($data, $stored, $actor): void {
            foreach (['name' => 'brand.name', 'primary_color' => 'brand.primary_color', 'accent_color' => 'brand.accent_color'] as $field => $key) {
                if (array_key_exists($field, $data)) {
                    $this->settings->set($key, $data[$field], $actor);
                }
            }
            foreach ($stored as $key => $path) {
                $this->settings->set($key, $path, $actor);
            }
        });

        $disk = Storage::disk((string) config('branding.disk'));
        foreach ($previous as $path) {
            $disk->delete($path);
        }
    }

    private function store(string $asset, UploadedFile $file): string
    {
        $ext = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => throw new DomainException('SVG and other image types are not allowed.', 'UNSUPPORTED_MEDIA_TYPE', 415),
        };
        $contents = $file->getContent();
        if (str_contains(strtolower(substr($contents, 0, 512)), '<svg')) {
            throw new DomainException('SVG images are not allowed.', 'UNSUPPORTED_MEDIA_TYPE', 415);
        }

        $path = $asset.'-'.sha1($contents).'.'.$ext;
        Storage::disk((string) config('branding.disk'))->put($path, $contents);

        return $path;
    }
}
