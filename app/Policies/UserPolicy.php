<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * HR side of users (UC-H2, UC-H5, UC-M4). Coarse x-roles first (403), then visibility: a user the caller may not see
 * is a 404 (D-46). Identity admin (UC-U*, role `admin`) is `manageIdentity`, separate from HR field edits.
 */
final class UserPolicy
{
    /** GET /users: hr_admin all, manager their direct reports. */
    public function viewAny(User $actor): bool
    {
        return in_array($actor->role, [Role::Manager, Role::HrAdmin], true);
    }

    public function view(User $actor, User $user): Response
    {
        if (! $this->viewAny($actor)) {
            return Response::deny();
        }

        return $this->viewBalances($actor, $user);
    }

    /** Balances and ledger: the user, their direct manager, hr_admin; anyone else 404. */
    public function viewBalances(User $actor, User $user): Response
    {
        return $actor->role === Role::HrAdmin || $actor->id === $user->id || $user->manager_id === $actor->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $actor): bool
    {
        return $actor->role === Role::HrAdmin;
    }

    /** Deactivate / activate: hr_admin, never themselves (would lock HR out). */
    public function deactivate(User $actor, User $user): Response
    {
        if (! $this->update($actor)) {
            return Response::deny();
        }

        return $actor->id === $user->id ? Response::deny('You cannot change your own active state.') : Response::allow();
    }

    /** UC-H5 manual adjustment. */
    public function adjustBalance(User $actor): bool
    {
        return $actor->role === Role::HrAdmin;
    }

    /** Leave encashment. hr_admin only. */
    public function encash(User $actor): bool
    {
        return $actor->role === Role::HrAdmin;
    }

    /** UC-U1–U6. The additive realm role `admin` only (D-29). Guardrails G2–G3 stay in UserAdminGuard. */
    public function manageIdentity(User $actor): bool
    {
        return $actor->is_admin === true;
    }
}
