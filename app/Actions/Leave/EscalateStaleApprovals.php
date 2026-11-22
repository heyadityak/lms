<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Enums\LeaveStatus;
use App\Enums\Module;
use App\Enums\Role;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestEscalated;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * Pending requests older than lms.escalation_days are mailed to HR and stamped escalated_at.
 * Notifications off, or no other hr_admin, writes nothing.
 */
final class EscalateStaleApprovals
{
    public function handle(): int
    {
        if (! app(Modules::class)->enabled(Module::Notifications)) {
            return 0;
        }

        $cutoff = now()->subDays((int) config('lms.escalation_days', 3));
        $escalated = 0;

        $pending = LeaveRequest::query()
            ->where('status', LeaveStatus::Pending)
            ->whereNull('escalated_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id');

        foreach ($pending->lazyById() as $request) {
            $stamped = DB::transaction(function () use ($request): bool {
                $locked = LeaveRequest::query()->whereKey($request->id)->whereNull('escalated_at')->lockForUpdate()->first();
                if ($locked === null || $locked->status !== LeaveStatus::Pending) {
                    return false;
                }

                $hr = User::query()->where(['role' => Role::HrAdmin, 'is_active' => true])->whereKeyNot($locked->user_id)->get();
                if ($hr->isEmpty()) {
                    return false;
                }

                $pendingDays = (int) $locked->created_at->diffInDays(now());
                $locked->load(['user', 'leaveType']);
                LeaveTransition::notify($hr, new LeaveRequestEscalated($locked, max(1, $pendingDays)));
                $locked->forceFill(['escalated_at' => now()])->save();

                return true;
            });
            if ($stamped) {
                $escalated++;
            }
        }

        return $escalated;
    }
}
