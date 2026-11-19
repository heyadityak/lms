<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/** USE_CASES §3.6, resolved at action time: active direct manager, else any active hr_admin; never the requester. */
final class ApproverResolver
{
    /** @return Collection<int, User> */
    public function approversFor(User $requester): Collection
    {
        $manager = $requester->manager;
        if ($manager !== null && $manager->is_active && ! $manager->is($requester)) {
            return collect([$manager]);
        }

        return User::query()->where(['role' => Role::HrAdmin, 'is_active' => true])->whereKeyNot($requester->id)->get();
    }

    /** Direct manager or any hr_admin, active, and never on their own request. */
    public function canDecide(User $actor, LeaveRequest $request): bool
    {
        if (! $actor->is_active || $actor->id === $request->user_id) {
            return false;
        }

        return $actor->role === Role::HrAdmin || $request->user->manager_id === $actor->id;
    }
}
