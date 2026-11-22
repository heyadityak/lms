<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Settings\UpdateWorkingWeek;
use App\Http\Requests\UpdateWorkingWeekRequest;
use App\Http\Resources\WorkingWeekResource;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** UC-H16. GET any authenticated role; PATCH is hr_admin. Existing leave_requests.days are not rewritten. */
final class WorkingWeekController
{
    public function show(Settings $settings): JsonResponse
    {
        Gate::authorize('viewWorkingWeek', Setting::class);

        return WorkingWeekResource::response($settings);
    }

    public function update(UpdateWorkingWeekRequest $request, UpdateWorkingWeek $action, Settings $settings): JsonResponse
    {
        $action->handle($request->validated('weekend_days'), $request->user());

        return WorkingWeekResource::response($settings);
    }
}
