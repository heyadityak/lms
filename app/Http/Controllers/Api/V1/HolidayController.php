<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\RecordAuditLog;
use App\Http\Requests\HolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Models\Location;
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

        return HolidayResource::collection(self::ofYear($year)->load('location'))->additional(['meta' => new stdClass, 'links' => new stdClass]);
    }

    public function show(Holiday $holiday): JsonResponse
    {
        return (new HolidayResource($holiday->load('location')))->envelope();
    }

    public function store(HolidayRequest $request, RecordAuditLog $audit): JsonResponse
    {
        $holiday = Holiday::query()->create(self::attributes($request->validated()))->load('location');
        $audit->write($request->user(), 'holiday', $holiday->public_id, 'created', $request->validated());

        return (new HolidayResource($holiday))->envelope(201, route('api.v1.holidays.show', $holiday));
    }

    public function update(HolidayRequest $request, Holiday $holiday, RecordAuditLog $audit): JsonResponse
    {
        $holiday->update(self::attributes($request->validated()));
        $audit->write($request->user(), 'holiday', $holiday->public_id, 'updated', $request->validated());

        return (new HolidayResource($holiday->load('location')))->envelope();
    }

    public function destroy(Holiday $holiday, RecordAuditLog $audit): Response
    {
        Gate::authorize('delete', $holiday);
        $audit->write(request()->user(), 'holiday', $holiday->public_id, 'deleted', ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);
        $holiday->delete();

        return response()->noContent();
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        if (array_key_exists('location_id', $data)) {
            $data['location_id'] = $data['location_id'] === null ? null : Location::query()->where('public_id', $data['location_id'])->value('id');
        }

        return $data;
    }

    /** @return Collection<int, Holiday> */
    public static function ofYear(int $year): Collection
    {
        return Holiday::query()->with('location')->whereBetween('date', [LeaveYear::start($year)->toDateString(), LeaveYear::end($year)->toDateString()])->orderBy('date')->get();
    }
}
