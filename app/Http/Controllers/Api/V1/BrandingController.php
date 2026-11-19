<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Support\Brand;
use App\Support\Modules;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use stdClass;

/** GET /api/v1/branding (`getPublicBranding`, D-43): public, no auth, not module-gated; enabled module keys only. */
final class BrandingController
{
    public function show(Settings $settings, Modules $modules): JsonResponse
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
                'modules' => array_keys(array_filter($modules->all(), fn (array $row): bool => $row['enabled'])),
            ],
            'meta' => new stdClass,
            'links' => new stdClass,
        ])->header('Cache-Control', 'public, max-age=30');
    }
}
