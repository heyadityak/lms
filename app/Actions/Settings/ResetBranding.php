<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** UC-B6. Deletes brand override rows and the stored logo/favicon. Env defaults apply again. */
final class ResetBranding
{
    private const KEYS = ['brand.name', 'brand.primary_color', 'brand.accent_color', 'brand.logo_path', 'brand.favicon_path'];

    public function __construct(private readonly Settings $settings) {}

    public function handle(User $actor): void
    {
        $paths = [];
        foreach (['brand.logo_path', 'brand.favicon_path'] as $key) {
            if (! $this->settings->isOverridden($key)) {
                continue;
            }
            $path = $this->settings->get($key);
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        DB::transaction(function (): void {
            foreach (self::KEYS as $key) {
                $this->settings->forget($key);
            }
        });

        $disk = Storage::disk((string) config('branding.disk'));
        foreach ($paths as $path) {
            $disk->delete($path);
        }

        Log::info('branding.reset', ['actor_id' => $actor->id]);
    }
}
