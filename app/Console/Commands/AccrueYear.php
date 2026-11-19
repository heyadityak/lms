<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accrual\RunYearStartAccrual;
use App\Support\LeaveYear;
use Illuminate\Console\Command;

/** UC-H9 / UC-S1: `lms:accrue-year {year?}` (default = current leave year). Safe to re-run. */
final class AccrueYear extends Command
{
    protected $signature = 'lms:accrue-year {year? : Leave year label (default: current leave year)}';

    protected $description = 'Year-start accrual: lapse/carry-forward the previous leave year and grant the annual quota (idempotent)';

    public function handle(RunYearStartAccrual $accrual): int
    {
        $year = $this->argument('year') ?? (string) LeaveYear::current();
        if (! ctype_digit($year) || (int) $year < 2000 || (int) $year > 2100) {
            $this->error('year must be an integer between 2000 and 2100.');

            return self::INVALID;
        }

        $summary = $accrual->handle((int) $year);
        $this->info("Leave year {$summary->year}: {$summary->users} users, {$summary->rowsWritten} ledger rows written, {$summary->rowsSkipped} already present.");

        return self::SUCCESS;
    }
}
