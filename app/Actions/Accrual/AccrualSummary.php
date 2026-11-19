<?php

declare(strict_types=1);

namespace App\Actions\Accrual;

/** Result of RunYearStartAccrual (logged and printed by lms:accrue-year). */
final readonly class AccrualSummary
{
    public function __construct(
        public int $year,
        public int $users,
        public int $rowsWritten,
        public int $rowsSkipped,
    ) {}
}
