<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DayPart;
use App\Services\AccrualCalculator;
use App\Services\WorkingDayCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class LeaveMathTest extends TestCase
{
    private static function days(string $start, string $end, DayPart $part = DayPart::Full, array $weekend = [6, 7], array $holidays = []): float
    {
        return WorkingDayCalculator::count(CarbonImmutable::parse($start), CarbonImmutable::parse($end), $part, $weekend, $holidays);
    }

    public function test_weekends_and_holidays_are_excluded_inclusive_range(): void
    {
        // Mon 2026-12-21 … Sun 2026-12-27, Christmas on Friday.
        $this->assertSame(5.0, self::days('2026-12-21', '2026-12-27'));
        $this->assertSame(4.0, self::days('2026-12-21', '2026-12-27', holidays: ['2026-12-25']));
        $this->assertSame(0.0, self::days('2026-12-26', '2026-12-27'));
        $this->assertSame(1.0, self::days('2026-12-21', '2026-12-21'));
    }

    public function test_custom_weekend_and_half_day(): void
    {
        $this->assertSame(5.0, self::days('2026-12-21', '2026-12-27', weekend: [5, 6]));
        $this->assertSame(0.5, self::days('2026-12-21', '2026-12-21', DayPart::FirstHalf));
        $this->assertSame(0.0, self::days('2026-12-26', '2026-12-26', DayPart::SecondHalf));
    }

    public function test_proration_and_clipping(): void
    {
        $this->assertSame(5.0, AccrualCalculator::prorate(10, 7, 1));   // Jul..Dec = 6 months
        $this->assertSame(7.5, AccrualCalculator::prorate(15, 7, 1));
        $this->assertSame(10.0, AccrualCalculator::prorate(10, 4, 4));  // joins in the start month
        $this->assertSame(0.5, AccrualCalculator::prorate(10, 3, 4));   // last month: 10/12 floored to a half
        $this->assertSame(20.0, AccrualCalculator::clipGrant(30, 280, 300));
        $this->assertSame(30.0, AccrualCalculator::clipGrant(30, 0, null));
    }
}
