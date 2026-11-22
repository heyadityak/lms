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

// UC-S4: 08:00 in the scheduler default timezone (APP_TIMEZONE). Do not call timezone(). Redis locks cover N schedulers.
Schedule::command('lms:remind-pending-approvals')
    ->dailyAt('08:00')
    ->onOneServer()
    ->withoutOverlapping();

// Monthly types: 1st of the month at 00:20 APP_TIMEZONE. Yearly types are not granted here.
Schedule::command('lms:accrue-month')
    ->monthlyOn(1, '00:15')
    ->onOneServer()
    ->withoutOverlapping();

// Pending requests older than LEAVE_ESCALATION_DAYS. Mail depends on the notifications module.
Schedule::command('lms:escalate-stale-approvals')
    ->dailyAt('09:00')
    ->onOneServer()
    ->withoutOverlapping();
