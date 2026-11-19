<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Leave\CancelLeaveRequest;
use App\Enums\LeaveStatus;
use App\Exceptions\InvalidTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * UC-H2 / USE_CASES §3.9: the JWT guard refuses the user from now on (401), their pending requests are cancelled
 * with the deactivating actor on the action row. Their reports' pending requests need no rewrite: ApproverResolver
 * resolves at action time and skips an inactive manager, so they fall to hr_admin (USE_CASES §3.6).
 */
final class DeactivateUser
{
    public function __construct(private readonly CancelLeaveRequest $cancel) {}

    public function handle(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw new InvalidTransition('User is already inactive.');
            }
            $locked->forceFill(['is_active' => false])->save();

            foreach ($locked->leaveRequests()->where('status', LeaveStatus::Pending)->get() as $request) {
                $this->cancel->handle($request, $actor, 'Cancelled automatically: user deactivated.');
            }
        });
    }
}
