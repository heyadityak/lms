<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Services\LeaveBalanceService;
use App\Support\LeaveYear;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** UC-E4: balances of the current leave year. Guests see the landing page. */
final class DashboardController
{
    public function __invoke(Request $request, LeaveBalanceService $balances): View
    {
        if ($request->user() === null) {
            return view('welcome');
        }

        $year = LeaveYear::current();

        return view('dashboard', [
            'year' => $year,
            'yearStart' => LeaveYear::start($year),
            'yearEnd' => LeaveYear::end($year),
            'balances' => $balances->summary($request->user(), $year),
        ]);
    }
}
