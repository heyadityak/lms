<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\Module;
use App\Enums\Role;
use App\Models\ApprovalReminder;
use App\Models\User;
use App\Notifications\PendingApprovalsReminder;
use App\Services\LeaveRequestQuery;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * UC-S4. One mail per active manager or hr_admin who can see a pending request in the approvals inbox.
 * Unique (user, calendar date) is inserted before the job is queued. A same-day re-run inserts nothing.
 */
final class SendPendingApprovalReminders
{
    public function handle(): PendingApprovalReminderSummary
    {
        $today = now()->toDateString();
        if (! app(Modules::class)->enabled(Module::Notifications)) {
            return new PendingApprovalReminderSummary($today, 0, 0, 0);
        }

        $queued = 0;
        $nothingPending = 0;
        $alreadyReminded = 0;

        $candidates = User::query()
            ->where('is_active', true)
            ->whereIn('role', [Role::Manager, Role::HrAdmin])
            ->orderBy('id');

        foreach ($candidates->lazyById() as $user) {
            $count = LeaveRequestQuery::approvableBy($user)->count();
            if ($count < 1) {
                $nothingPending++;

                continue;
            }

            DB::transaction(function () use ($user, $today, $count, &$queued, &$alreadyReminded): void {
                $inserted = ApprovalReminder::query()->insertOrIgnore([
                    'user_id' => $user->id,
                    'reminded_on' => $today,
                    'created_at' => now(),
                ]);
                if ($inserted !== 1) {
                    $alreadyReminded++;

                    return;
                }

                $user->notify(new PendingApprovalsReminder($count));
                $queued++;
            });
        }

        return new PendingApprovalReminderSummary($today, $queued, $nothingPending, $alreadyReminded);
    }
}
