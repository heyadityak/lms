<?php

declare(strict_types=1);

namespace App\Enums;

/** USE_CASES §3.6. The enum decides whether a transition is allowed; Policies decide who. */
enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => in_array($to, [self::Approved, self::Rejected, self::Cancelled], true),
            self::Approved => $to === self::Cancelled,
            self::Rejected, self::Cancelled => false,
        };
    }

    /** Statuses that block overlapping dates (V6, `leave_requests_no_overlap`). */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
