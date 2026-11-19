<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// UC-S1: day 1 of the leave year (LEAVE_YEAR_START_MONTH, D-26) at 00:10 APP_TIMEZONE; Redis locks, safe on N schedulers.
Schedule::command('lms:accrue-year')
    ->yearlyOn((int) config('lms.leave_year_start_month', 1), 1, '00:10')
    ->onOneServer()
    ->withoutOverlapping();
