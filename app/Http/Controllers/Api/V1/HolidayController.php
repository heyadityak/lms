<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\HolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Support\LeaveYear;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use stdClass;

/** UC-E8 / UC-H4 (module `holidays`). Changes never recompute existing requests (USE_CASES §3.9). */
final class HolidayController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $year = (int) ($request->validate(['filter' => ['sometimes', 'array:year'], 'filter.year' => ['sometimes', 'integer', 'between:2000,2100']])['filter']['year'] ?? LeaveYear::current());

        return HolidayResource::collection(self::ofYear($year))->additional(['meta' => new stdClass, 'links' => new stdClass]);
    }

    public function show(Holiday $holiday): JsonResponse
    {
        return (new HolidayResource($holiday))->envelope();
    }

    public function store(HolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::query()->create($request->validated());

        return (new HolidayResource($holiday))->envelope(201, route('api.v1.holidays.show', $holiday));
    }

    public function update(HolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $holiday->update($request->validated());

        return (new HolidayResource($holiday))->envelope();
    }

    public function destroy(Holiday $holiday): Response
    {
        Gate::authorize('delete', $holiday);
        $holiday->delete();

        return response()->noContent();
    }

    /** @return Collection<int, Holiday> */
    public static function ofYear(int $year): Collection
    {
        return Holiday::query()->whereBetween('date', [LeaveYear::start($year)->toDateString(), LeaveYear::end($year)->toDateString()])->orderBy('date')->get();
    }
}
