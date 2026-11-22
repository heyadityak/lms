<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Leave\EscalateStaleApprovals as EscalateStaleApprovalsAction;
use App\Enums\Module;
use App\Support\Modules;
use Illuminate\Console\Command;

/** `lms:escalate-stale-approvals`. Stamps escalated_at once; mail only when notifications is on. */
final class EscalateStaleApprovals extends Command
{
    protected $signature = 'lms:escalate-stale-approvals';

    protected $description = 'Escalate pending leave requests older than LEAVE_ESCALATION_DAYS to HR';

    public function handle(EscalateStaleApprovalsAction $escalate): int
    {
        if (! app(Modules::class)->enabled(Module::Notifications)) {
            $this->info('Notifications module is off; no stale approvals escalated.');

            return self::SUCCESS;
        }

        $count = $escalate->handle();
        $this->info("Escalated {$count} pending leave requests to HR.");

        return self::SUCCESS;
    }
}
