<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Module;
use App\Enums\Role;
use App\Models\ApprovalDelegation;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Support\Collection;

/**
 * USE_CASES §3.6, resolved at action time: active direct manager whose base role is manager or hr_admin
 * (openapi x-roles of approve/reject), else any active hr_admin; never the requester.
 * A delegation covering today replaces that person. Module multi_level_approval sends long requests to HR.
 */
final class ApproverResolver
{
    /** @return Collection<int, User> */
    public function approversFor(User $requester, ?float $days = null): Collection
    {
        if ($this->routesToHr($days)) {
            return $this->hrPool($requester);
        }

        $manager = $requester->manager;
        if ($manager !== null && $manager->is_active && self::approverRole($manager) && ! $manager->is($requester)) {
            return collect([$this->delegatedApprover($manager, $requester) ?? $manager]);
        }

        return $this->hrPool($requester);
    }

    /** Direct manager (role manager) or any hr_admin, active, and never on their own request. */
    public function canDecide(User $actor, LeaveRequest $request): bool
    {
        if (! $actor->is_active || ! self::approverRole($actor) || $actor->id === $request->user_id) {
            return false;
        }

        $owner = $request->user;
        if ($this->routesToHr((float) $request->days)) {
            if ($actor->role === Role::HrAdmin) {
                return true;
            }

            return User::query()->where(['role' => Role::HrAdmin, 'is_active' => true])->whereKeyNot($owner->id)->get()
                ->contains(fn (User $hr): bool => $this->delegatedApprover($hr, $owner)?->is($actor) === true);
        }

        if ($actor->role === Role::HrAdmin) {
            return true;
        }
        if ($owner->manager_id === $actor->id) {
            return $this->delegationFor($actor) === null;
        }

        $manager = $owner->manager;

        return $manager !== null && $this->delegatedApprover($manager, $owner)?->is($actor) === true;
    }

    /** True when $actor is the active delegate of $delegator today. */
    public function isCurrentDelegate(User $actor, User $delegator): bool
    {
        return $this->delegationFor($delegator)?->delegate_id === $actor->id;
    }

    private function routesToHr(?float $days): bool
    {
        if ($days === null || ! app(Modules::class)->enabled(Module::MultiLevel)) {
            return false;
        }

        return $days >= (float) config('lms.hr_approval_days');
    }

    /** @return Collection<int, User> */
    private function hrPool(User $requester): Collection
    {
        return User::query()->where(['role' => Role::HrAdmin, 'is_active' => true])->whereKeyNot($requester->id)->get()
            ->map(fn (User $hr): User => $this->delegatedApprover($hr, $requester) ?? $hr)
            ->unique(fn (User $user): int => $user->id)
            ->values();
    }

    private function delegatedApprover(User $delegator, User $requester): ?User
    {
        $delegation = $this->delegationFor($delegator);
        $delegate = $delegation?->delegate;
        if ($delegate === null || ! $delegate->is_active || ! self::approverRole($delegate) || $delegate->is($requester)) {
            return null;
        }

        return $delegate;
    }

    private function delegationFor(User $delegator): ?ApprovalDelegation
    {
        $today = now()->toDateString();

        return ApprovalDelegation::query()
            ->where('delegator_id', $delegator->id)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->with('delegate')
            ->first();
    }

    private static function approverRole(User $user): bool
    {
        return in_array($user->role, [Role::Manager, Role::HrAdmin], true);
    }
}
