<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Leave year labelled by the calendar year it starts in; starts on day 1 of LEAVE_YEAR_START_MONTH (D-26). */
final class LeaveYear
{
    public static function of(CarbonInterface $date): int
    {
        return $date->month >= self::startMonth() ? $date->year : $date->year - 1;
    }

    public static function start(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, self::startMonth(), 1);
    }

    public static function end(int $year): CarbonImmutable
    {
        return self::start($year)->addYear()->subDay();
    }

    public static function current(): int
    {
        return self::of(CarbonImmutable::now());
    }

    private static function startMonth(): int
    {
        return (int) config('lms.leave_year_start_month', 1);
    }
}
