<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use stdClass;

/** openapi `WorkingWeek`. */
final class WorkingWeekResource
{
    public static function response(Settings $settings): JsonResponse
    {
        return response()->json([
            'data' => [
                'weekend_days' => array_map(intval(...), array_values((array) $settings->get('work.weekend_days'))),
                'overridden' => $settings->isOverridden('work.weekend_days'),
            ],
            'meta' => new stdClass,
            'links' => new stdClass,
        ]);
    }
}
