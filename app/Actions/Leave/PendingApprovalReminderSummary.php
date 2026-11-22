<?php

declare(strict_types=1);

namespace App\Actions\Leave;

/** Result of SendPendingApprovalReminders. Module off is a zero summary (no scan). */
final readonly class PendingApprovalReminderSummary
{
    public function __construct(
        public string $date,
        public int $queued,
        public int $nothingPending,
        public int $alreadyReminded,
    ) {}
}
