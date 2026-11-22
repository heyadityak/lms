<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accrual\RunMonthlyAccrual;
use Illuminate\Console\Command;

/** Monthly accrual for leave types with accrual_frequency=monthly. Safe to re-run. */
final class AccrueMonth extends Command
{
    protected $signature = 'lms:accrue-month';

    protected $description = 'Grant one twelfth of the annual quota for monthly leave types (idempotent per user, type and month)';

    public function handle(RunMonthlyAccrual $accrual): int
    {
        $written = $accrual->handle();
        $this->info('Monthly accrual '.now()->format('Y-m').": {$written} ledger rows written.");

        return self::SUCCESS;
    }
}
