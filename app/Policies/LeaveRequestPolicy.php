<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\LeaveStatus;
use App\Enums\Role;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApproverResolver;
use Illuminate\Auth\Access\Response;

/**
 * Who may act (USE_CASES §3.6). Whether the status allows it is the state machine's job (409 in the Actions),
 * so these checks never look at pending/approved except for the "already started" rule.
 */
final class LeaveRequestPolicy
{
    public function __construct(private readonly ApproverResolver $approvers) {}

    /** Owner, their direct manager, or hr_admin. Others get 404 so ids do not leak. */
    public function view(User $user, LeaveRequest $request): Response
    {
        $allowed = $user->id === $request->user_id
            || $user->role === Role::HrAdmin
            || $request->user->manager_id === $user->id;

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    /** Approve or reject: active direct manager or hr_admin, never the requester. */
    public function decide(User $user, LeaveRequest $request): Response
    {
        if ($user->id === $request->user_id) {
            return Response::deny('You cannot decide on your own leave request.');
        }

        return $this->approvers->canDecide($user, $request) ? Response::allow() : Response::deny('Only the direct manager or HR can decide on this request.');
    }

    /** Owner: until the approved leave starts. hr_admin: any time, except their own started leave. */
    public function cancel(User $user, LeaveRequest $request): Response
    {
        $started = $request->status === LeaveStatus::Approved && $request->start_date->toDateString() <= now()->toDateString();

        if ($user->id === $request->user_id) {
            return $started ? Response::deny('Leave that has already started can only be cancelled by HR.') : Response::allow();
        }

        return $user->role === Role::HrAdmin ? Response::allow() : Response::deny();
    }

    /** Approval inbox (x-roles manager, hr_admin). */
    public function viewApprovals(User $user): bool
    {
        return in_array($user->role, [Role::Manager, Role::HrAdmin], true);
    }
}
