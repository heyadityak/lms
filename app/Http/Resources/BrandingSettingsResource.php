<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Brand;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use stdClass;

/** openapi `Branding` (admin settings view, including which keys are overridden). */
final class BrandingSettingsResource
{
    private const KEYS = ['brand.name', 'brand.primary_color', 'brand.accent_color', 'brand.logo_path', 'brand.favicon_path'];

    public static function response(Settings $settings): JsonResponse
    {
        $brand = Brand::fromSettings($settings);

        return response()->json([
            'data' => [
                'name' => $brand->name,
                'primary_color' => $brand->primary,
                'accent_color' => $brand->accent,
                'primary_contrast' => $brand->contrast,
                'logo_url' => $brand->logoUrl,
                'favicon_url' => $brand->faviconUrl,
                'overridden' => array_values(array_filter(self::KEYS, fn (string $key): bool => $settings->isOverridden($key))),
            ],
            'meta' => new stdClass,
            'links' => new stdClass,
        ]);
    }
}
