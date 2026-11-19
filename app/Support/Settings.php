<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * effective(key) = DB override (`settings` table) ?? env default from config (D-14, SYSTEM_DESIGN §8.1).
 * Overrides are cached in the shared cache store (Redis) and busted on every write. Bound as `scoped`, so the
 * per-instance memo lives for one request only, also under Octane (D-27).
 */
final class Settings
{
    public const CACHE_KEY = 'settings:all';

    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    public function get(string $key): mixed
    {
        $this->overrides ??= Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $this->overrides) ? $this->overrides[$key] : self::default($key);
    }

    public function set(string $key, mixed $value, ?User $actor = null): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actor?->id]);
        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::query()->whereKey($key)->delete();
        $this->flush();
    }

    public static function default(string $key): mixed
    {
        return match ($key) {
            'brand.name' => config('branding.name'),
            'brand.primary_color' => config('branding.primary_color'),
            'brand.accent_color' => config('branding.accent_color'),
            'modules.disabled' => [],
            'work.weekend_days' => config('lms.weekend_days'),
            default => null, // brand.logo_path, brand.favicon_path: bundled defaults
        };
    }

    private function flush(): void
    {
        $this->overrides = null;
        // After commit: busting earlier would let a concurrent request re-cache the old row forever.
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
    }
}
