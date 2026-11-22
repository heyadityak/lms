<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Settings\DeleteBrandingAsset;
use App\Actions\Settings\ResetBranding;
use App\Actions\Settings\UpdateBranding;
use App\Http\Requests\UpdateBrandingRequest;
use App\Http\Requests\UploadBrandingAssetRequest;
use App\Http\Resources\BrandingSettingsResource;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** UC-B1–B4, UC-B6. Writes are role `admin`. GET is any authenticated user. */
final class BrandingController
{
    public function show(Settings $settings): JsonResponse
    {
        Gate::authorize('viewBranding', Setting::class);

        return BrandingSettingsResource::response($settings);
    }

    public function update(UpdateBrandingRequest $request, UpdateBranding $action, Settings $settings): JsonResponse
    {
        $action->handle($request->validated(), null, null, $request->user());

        return BrandingSettingsResource::response($settings);
    }

    public function reset(Request $request, ResetBranding $action, Settings $settings): JsonResponse
    {
        Gate::authorize('manageBranding', Setting::class);
        $action->handle($request->user());

        return BrandingSettingsResource::response($settings);
    }

    public function upload(UploadBrandingAssetRequest $request, string $asset, UpdateBranding $action, Settings $settings): JsonResponse
    {
        $file = $request->file('file');
        $action->handle(
            [],
            $asset === 'logo' ? $file : null,
            $asset === 'favicon' ? $file : null,
            $request->user(),
        );

        return BrandingSettingsResource::response($settings);
    }

    public function destroy(string $asset, DeleteBrandingAsset $action, Settings $settings): JsonResponse
    {
        Gate::authorize('manageBranding', Setting::class);
        $action->handle($asset);

        return BrandingSettingsResource::response($settings);
    }
}
