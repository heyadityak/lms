<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Leave\SendPendingApprovalReminders;
use App\Enums\Module;
use App\Support\Modules;
use Illuminate\Console\Command;

/** UC-S4: `lms:remind-pending-approvals`. Safe to re-run; approval_reminders is one row per recipient per day. */
final class RemindPendingApprovals extends Command
{
    protected $signature = 'lms:remind-pending-approvals';

    protected $description = 'Email active managers and HR admins who have pending leave requests they can approve (once per day)';

    public function handle(SendPendingApprovalReminders $reminders): int
    {
        $summary = $reminders->handle();
        if (! app(Modules::class)->enabled(Module::Notifications)) {
            $this->info('Notifications module is off; no pending-approval reminders sent.');

            return self::SUCCESS;
        }

        $this->info("Pending approval reminders for {$summary->date}: {$summary->queued} queued, {$summary->nothingPending} nothingPending, {$summary->alreadyReminded} alreadyReminded.");

        return self::SUCCESS;
    }
}
