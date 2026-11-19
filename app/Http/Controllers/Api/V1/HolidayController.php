<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Support\LeaveYear;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use stdClass;

/** Read side only (module `holidays`); CRUD (UC-H4) is phase 8. */
final class HolidayController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $year = (int) ($request->validate(['filter' => ['sometimes', 'array:year'], 'filter.year' => ['sometimes', 'integer', 'between:2000,2100']])['filter']['year'] ?? LeaveYear::current());

        return HolidayResource::collection(self::ofYear($year))->additional(['meta' => new stdClass, 'links' => new stdClass]);
    }

    /** @return Collection<int, Holiday> */
    public static function ofYear(int $year): Collection
    {
        return Holiday::query()->whereBetween('date', [LeaveYear::start($year)->toDateString(), LeaveYear::end($year)->toDateString()])->orderBy('date')->get();
    }
}
