<?php

declare(strict_types=1);

namespace App\Services;

/** Pure accrual maths (USE_CASES §3.7). Carry-forward helpers arrive with the year-start job (phase 8). */
final class AccrualCalculator
{
    /** quota × months remaining in the leave year (joining month counts) / 12, floored to a half day. */
    public static function prorate(float $quota, int $joinMonth, int $startMonth): float
    {
        $monthsRemaining = 12 - (($joinMonth - $startMonth + 12) % 12);

        return self::floorToHalf($quota * $monthsRemaining / 12);
    }

    /** Clip a grant so carry + grant never exceeds max_balance (null = no ceiling). */
    public static function clipGrant(float $grant, float $carry, ?float $maxBalance): float
    {
        return $maxBalance === null ? $grant : max(0.0, min($grant, $maxBalance - $carry));
    }

    public static function floorToHalf(float $days): float
    {
        return floor($days * 2) / 2;
    }
}
