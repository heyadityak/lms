<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** UC-B3 / UC-B4. Removes one uploaded asset so `/branding/{asset}` falls back to the bundled default. */
final class DeleteBrandingAsset
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(string $asset): void
    {
        $key = "brand.{$asset}_path";
        $path = $this->settings->isOverridden($key) ? $this->settings->get($key) : null;

        DB::transaction(function () use ($key): void {
            $this->settings->forget($key);
        });

        if (is_string($path) && $path !== '') {
            Storage::disk((string) config('branding.disk'))->delete($path);
        }
    }
}
