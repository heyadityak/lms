<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * USE_CASES §3.6, resolved at action time: active direct manager whose base role is manager or hr_admin
 * (openapi x-roles of approve/reject), else any active hr_admin; never the requester.
 */
final class ApproverResolver
{
    /** @return Collection<int, User> */
    public function approversFor(User $requester): Collection
    {
        $manager = $requester->manager;
        if ($manager !== null && $manager->is_active && self::approverRole($manager) && ! $manager->is($requester)) {
            return collect([$manager]);
        }

        return User::query()->where(['role' => Role::HrAdmin, 'is_active' => true])->whereKeyNot($requester->id)->get();
    }

    /** Direct manager (role manager) or any hr_admin, active, and never on their own request. */
    public function canDecide(User $actor, LeaveRequest $request): bool
    {
        if (! $actor->is_active || ! self::approverRole($actor) || $actor->id === $request->user_id) {
            return false;
        }

        return $actor->role === Role::HrAdmin || $request->user->manager_id === $actor->id;
    }

    private static function approverRole(User $user): bool
    {
        return in_array($user->role, [Role::Manager, Role::HrAdmin], true);
    }
}
