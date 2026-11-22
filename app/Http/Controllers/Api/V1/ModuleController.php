<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Settings\UpdateModules;
use App\Enums\Module;
use App\Http\Requests\UpdateModuleRequest;
use App\Http\Resources\ModuleResource;
use App\Models\Setting;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** UC-B5. Role `admin`. Enabling an unlicensed or unavailable module is 422 MODULE_NOT_LICENSED. */
final class ModuleController
{
    public function index(Modules $modules): JsonResponse
    {
        Gate::authorize('manageModules', Setting::class);

        return ModuleResource::list($modules);
    }

    public function update(UpdateModuleRequest $request, string $module, UpdateModules $action, Modules $modules): JsonResponse
    {
        $key = Module::from($module);
        $action->handle($key, $request->boolean('enabled'), $request->user());

        return ModuleResource::one($modules, $key);
    }
}
