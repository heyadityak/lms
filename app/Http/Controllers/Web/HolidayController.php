<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\V1\HolidayController as ApiHolidayController;
use App\Support\LeaveYear;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** UC-E8: read-only holiday list of a leave year (module `holidays`). */
final class HolidayController
{
    public function index(Request $request): View
    {
        $year = (int) ($request->validate(['year' => ['sometimes', 'integer', 'between:2000,2100']])['year'] ?? LeaveYear::current());

        return view('holidays.index', ['year' => $year, 'holidays' => ApiHolidayController::ofYear($year)]);
    }
}
