<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DayPart;
use App\Enums\Module;
use App\Models\Holiday;
use App\Support\Modules;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/** USE_CASES §3.3: inclusive range minus weekend days and (module `holidays` on) holidays; half-day = 0.5. */
final class WorkingDayCalculator
{
    public function __construct(private readonly Settings $settings, private readonly Modules $modules) {}

    /**
     * Pure: no DB, no config (Unit-testable).
     *
     * @param  list<int>  $weekendIsoDays  1 = Mon … 7 = Sun
     * @param  list<string>  $holidayDates  Y-m-d
     */
    public static function count(CarbonInterface $start, CarbonInterface $end, DayPart $part, array $weekendIsoDays, array $holidayDates): float
    {
        $days = 0;
        foreach (CarbonPeriod::create($start->toDateString(), $end->toDateString()) as $day) {
            if (! in_array($day->dayOfWeekIso, $weekendIsoDays, true) && ! in_array($day->toDateString(), $holidayDates, true)) {
                $days++;
            }
        }

        // A half day is only valid on a single date (V4 is enforced by the caller); it counts 0.5 if that date is worked.
        return $part->isHalf() ? $days * 0.5 : (float) $days;
    }

    /** Effective weekend (setting `work.weekend_days` ?? env, D-30) and holidays (only when the module is on). */
    public function forRange(CarbonInterface $start, CarbonInterface $end, DayPart $part): float
    {
        $holidays = $this->modules->enabled(Module::Holidays)
            ? Holiday::query()->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->map(fn (Holiday $h): string => $h->date->toDateString())->all()
            : [];

        return self::count($start, $end, $part, array_map('intval', (array) $this->settings->get('work.weekend_days')), $holidays);
    }
}
