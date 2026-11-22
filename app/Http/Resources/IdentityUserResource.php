<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use stdClass;

/** openapi `IdentityUser`: Keycloak profile merged with LMS fields. */
final class IdentityUserResource
{
    /** @param  array<string, mixed>  $kc
     * @param  list<string>  $roles
     */
    public static function respond(User $user, array $kc, array $roles, int $status = 200): JsonResponse
    {
        $self = route('api.v1.admin.users.show', $user);

        return response()->json([
            'data' => self::data($user, $kc, $roles),
            'meta' => new stdClass,
            'links' => ['self' => $self],
        ], $status)->withHeaders($status === 201 ? ['Location' => $self] : []);
    }

    /**
     * @param  array<string, mixed>  $kc
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    public static function data(User $user, array $kc, array $roles): array
    {
        $base = Role::fromKeycloakRoles($roles);
        $link = $kc['federationLink'] ?? null;

        return [
            'id' => $user->public_id,
            'keycloak_id' => (string) ($kc['id'] ?? $user->keycloak_sub),
            'username' => (string) ($kc['username'] ?? ''),
            'email' => isset($kc['email']) && $kc['email'] !== '' ? (string) $kc['email'] : null,
            'first_name' => isset($kc['firstName']) && $kc['firstName'] !== '' ? (string) $kc['firstName'] : null,
            'last_name' => isset($kc['lastName']) && $kc['lastName'] !== '' ? (string) $kc['lastName'] : null,
            'enabled' => (bool) ($kc['enabled'] ?? false),
            'email_verified' => (bool) ($kc['emailVerified'] ?? false),
            'role' => $base?->value,
            'is_admin' => in_array(Role::Admin->value, $roles, true),
            'federated' => filled($link),
            'federation_link' => filled($link) ? (string) $link : null,
            'department' => $user->department === null ? null : [
                'id' => $user->department->public_id,
                'code' => $user->department->code,
                'name' => $user->department->name,
            ],
            'manager' => UserResource::summary($user->manager),
            'date_of_joining' => $user->date_of_joining?->toDateString(),
            'employee_code' => $user->employee_code,
            'designation' => $user->designation,
            'employment_type' => $user->employment_type,
            'is_active' => $user->is_active,
        ];
    }
}
