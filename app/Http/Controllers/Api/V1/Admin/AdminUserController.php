<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Users\AssignRolesAction;
use App\Actions\Users\CreateUserAction;
use App\Actions\Users\SendPasswordResetAction;
use App\Actions\Users\SetUserEnabledAction;
use App\Actions\Users\UpdateUserAction;
use App\Enums\Role;
use App\Http\Requests\ListIdentityUsersRequest;
use App\Http\Requests\SetUserRolesRequest;
use App\Http\Requests\StoreIdentityUserRequest;
use App\Http\Requests\UpdateIdentityUserRequest;
use App\Http\Resources\IdentityUserResource;
use App\Models\User;
use App\Services\IdentityUserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** UC-U1–U6. Module `user_management` and role `admin`. Keycloak is consulted before any identity payload is returned. */
final class AdminUserController
{
    public function index(ListIdentityUsersRequest $request, IdentityUserDirectory $directory): JsonResponse
    {
        $filter = (array) $request->validated('filter', []);

        return $directory->page(
            $request,
            $filter,
            (int) $request->validated('page.number', 1),
            (int) $request->validated('page.size', 25),
        );
    }

    public function store(StoreIdentityUserRequest $request, CreateUserAction $action): JsonResponse
    {
        $data = $request->validated();
        $user = $action->handle($data, $request->user());
        $role = (string) ($data['role'] ?? Role::Employee->value);
        $roles = ($data['is_admin'] ?? false) ? [$role, Role::Admin->value] : [$role];

        return IdentityUserResource::respond($user->load(['department', 'manager']), [
            'id' => $user->keycloak_sub,
            'username' => $data['username'],
            'email' => $user->email,
            'firstName' => $data['first_name'],
            'lastName' => $data['last_name'],
            'enabled' => (bool) ($data['enabled'] ?? true),
            'emailVerified' => false,
        ], $roles, 201);
    }

    public function show(User $user, IdentityUserDirectory $directory): JsonResponse
    {
        Gate::authorize('manageIdentity', User::class);

        return $directory->show($user);
    }

    public function update(UpdateIdentityUserRequest $request, User $user, UpdateUserAction $action, IdentityUserDirectory $directory): JsonResponse
    {
        $action->handle($user, $request->validated(), $request->user(), true);

        return $directory->show($user->refresh());
    }

    public function enable(User $user, SetUserEnabledAction $action, IdentityUserDirectory $directory, Request $request): JsonResponse
    {
        Gate::authorize('manageIdentity', User::class);
        $action->handle($user, true, $request->user());

        return $directory->show($user->refresh());
    }

    public function disable(User $user, SetUserEnabledAction $action, IdentityUserDirectory $directory, Request $request): JsonResponse
    {
        Gate::authorize('manageIdentity', User::class);
        $action->handle($user, false, $request->user());

        return $directory->show($user->refresh());
    }

    public function roles(SetUserRolesRequest $request, User $user, AssignRolesAction $action, IdentityUserDirectory $directory): JsonResponse
    {
        $action->handle($user, array_values($request->validated('roles')), $request->user());

        return $directory->show($user->refresh());
    }

    public function passwordReset(User $user, SendPasswordResetAction $action, Request $request): Response
    {
        Gate::authorize('manageIdentity', User::class);
        $action->handle($user, $request->user());

        return response()->noContent(202);
    }
}
