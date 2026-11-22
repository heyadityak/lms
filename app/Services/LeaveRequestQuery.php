<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Enums\Module;
use App\Enums\Role;
use App\Models\ApprovalDelegation;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveYear;
use App\Support\Modules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Read-side scoping, filters and sorting for leave-request lists (web + API). */
final class LeaveRequestQuery
{
    /** @return Builder<LeaveRequest> employee: own; manager: own + direct reports; hr_admin: all. */
    public static function visibleTo(User $user): Builder
    {
        $query = LeaveRequest::query();
        if ($user->role === Role::HrAdmin) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('user_id', $user->id)
            ->orWhereIn('user_id', User::query()->select('id')->where('manager_id', $user->id)));
    }

    /** @return Builder<LeaveRequest> pending requests the user may decide (never own). */
    public static function approvableBy(User $user): Builder
    {
        $query = LeaveRequest::query()->where('status', LeaveStatus::Pending)->where('user_id', '<>', $user->id);

        if ($user->role === Role::HrAdmin) {
            return $query;
        }

        $today = now()->toDateString();
        $delegatorIds = ApprovalDelegation::query()
            ->where('delegate_id', $user->id)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->pluck('delegator_id');
        $delegatedAway = ApprovalDelegation::query()
            ->where('delegator_id', $user->id)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();
        if (! $delegatedAway) {
            $delegatorIds->push($user->id);
        }

        $query->whereIn('user_id', User::query()->select('id')->whereIn('manager_id', $delegatorIds->unique()->all()));
        if (app(Modules::class)->enabled(Module::MultiLevel)) {
            $query->where('days', '<', (float) config('lms.hr_approval_days'));
        }

        return $query;
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @param  array<string, string>  $filter  validated by ListLeaveRequestsRequest
     * @return LengthAwarePaginator<int, LeaveRequest>
     */
    public static function page(Builder $query, array $filter, ?string $sort, int $size, int $number, bool $withActions = false): LengthAwarePaginator
    {
        self::filter($query, $filter);

        $sort ??= '-start_date';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');

        return $query->with(array_merge(['user', 'leaveType', 'decider'], $withActions ? ['actions.actor'] : []))
            ->paginate($size, ['*'], 'page[number]', $number);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @param  array<string, string>  $filter
     * @return Builder<LeaveRequest>
     */
    public static function filter(Builder $query, array $filter): Builder
    {
        if (isset($filter['status'])) {
            $query->whereIn('status', explode(',', $filter['status']));
        }
        if (isset($filter['year'])) {
            $year = (int) $filter['year'];
            $query->whereBetween('start_date', [LeaveYear::start($year)->toDateString(), LeaveYear::end($year)->toDateString()]);
        }
        if (isset($filter['from'])) {
            $query->where('end_date', '>=', $filter['from']);
        }
        if (isset($filter['to'])) {
            $query->where('start_date', '<=', $filter['to']);
        }
        if (isset($filter['user'])) {
            $query->whereHas('user', fn (Builder $q) => $q->where('public_id', $filter['user']));
        }
        if (isset($filter['leave_type'])) {
            $query->whereHas('leaveType', fn (Builder $q) => $q->where('public_id', $filter['leave_type']));
        }
        if (isset($filter['department'])) {
            $query->whereHas('user.department', fn (Builder $q) => $q->where('public_id', $filter['department']));
        }

        return $query;
    }
}
