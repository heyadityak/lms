<?php

declare(strict_types=1);

namespace App\Enums;

/** USE_CASES §3.5. Yearly kinds are unique per (user, type, year). */
enum LedgerKind: string
{
    case Grant = 'grant';
    case CarryForward = 'carry_forward';
    case Lapse = 'lapse';
    case Debit = 'debit';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
    case Encashment = 'encashment';

    public function isYearly(): bool
    {
        return in_array($this, [self::Grant, self::CarryForward, self::Lapse], true);
    }
}
