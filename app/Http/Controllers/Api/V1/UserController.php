<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Users\ActivateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\UpdateUserAction;
use App\Enums\Role;
use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LeaveBalanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** UC-H2 (LMS fields, local data only; roles stay in Keycloak) and UC-M4 (manager: direct reports, read-only). */
final class UserController
{
    public function index(ListUsersRequest $request): JsonResponse
    {
        $filter = (array) $request->validated('filter', []);
        $sort = (string) $request->validated('sort', 'name');
        $actor = $request->user();

        $query = User::query()->with(['department', 'manager', 'location'])
            ->when($actor->role !== Role::HrAdmin, fn (Builder $q) => $q->where('manager_id', $actor->id))
            ->when(isset($filter['department']), fn (Builder $q) => $q->whereHas('department', fn (Builder $d) => $d->where('public_id', $filter['department'])))
            ->when(isset($filter['is_active']), fn (Builder $q) => $q->where('is_active', in_array($filter['is_active'], ['true', '1'], true)))
            ->when(isset($filter['role']), fn (Builder $q) => $q->where('role', $filter['role']))
            ->when(isset($filter['q']), function (Builder $q) use ($filter): void {
                $like = '%'.addcslashes($filter['q'], '%_\\').'%';
                $q->where(fn (Builder $w) => $w->whereLike('name', $like)->orWhereLike('email', $like)->orWhereLike('employee_code', $like));
            })
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');

        return UserResource::page($query->paginate((int) $request->validated('page.size', 25), ['*'], 'page[number]', (int) $request->validated('page.number', 1)), $request);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return self::resource($user);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $update): JsonResponse
    {
        return self::resource($update->handle($user, $request->validated(), $request->user()));
    }

    public function deactivate(User $user, DeactivateUser $deactivate, Request $request): JsonResponse
    {
        Gate::authorize('deactivate', $user);
        $deactivate->handle($user, $request->user());

        return self::resource($user->refresh());
    }

    public function activate(User $user, ActivateUser $activate, Request $request): JsonResponse
    {
        Gate::authorize('deactivate', $user);
        $activate->handle($user, $request->user());

        return self::resource($user->refresh());
    }

    public function balances(Request $request, User $user, LeaveBalanceService $balances): AnonymousResourceCollection
    {
        Gate::authorize('viewBalances', $user);

        return MeController::balanceList($request, $user, $balances);
    }

    private static function resource(User $user): JsonResponse
    {
        return (new UserResource($user->load(['department', 'manager', 'location'])))->envelope();
    }
}
