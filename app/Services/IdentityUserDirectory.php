<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Http\Resources\IdentityUserResource;
use App\Models\User;
use App\Support\Keycloak\KeycloakAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * UC-U1. Keycloak is the source of the page. A Keycloak user with no local row is mirrored so the
 * response can carry a ULID. Keycloak being down throws IdpUnavailable before any local read is returned (D-32).
 */
final class IdentityUserDirectory
{
    public function __construct(private readonly KeycloakAdminClient $keycloak) {}

    /** @param  array<string, mixed>  $filter */
    public function page(Request $request, array $filter, int $page, int $size): JsonResponse
    {
        $filters = [];
        if (isset($filter['enabled'])) {
            $filters['enabled'] = in_array($filter['enabled'], ['true', '1'], true);
        }
        if (isset($filter['role'])) {
            $filters['role'] = (string) $filter['role'];
        }
        $q = (string) ($filter['q'] ?? '');
        $first = ($page - 1) * $size;

        $reps = $this->keycloak->searchUsers($q, $first, $size, $filters);
        $total = $this->keycloak->countUsers($q, $filters);
        $enriched = [];
        foreach ($reps as $rep) {
            if (! is_array($rep) || ! isset($rep['id'])) {
                continue;
            }
            $enriched[] = ['rep' => $rep, 'roles' => $this->keycloak->realmRolesOf((string) $rep['id'])];
        }

        $data = DB::transaction(function () use ($enriched): array {
            $rows = [];
            foreach ($enriched as $item) {
                $user = $this->mirror($item['rep'], $item['roles']);
                if ($user === null) {
                    continue;
                }
                $rows[] = IdentityUserResource::data($user->load(['department', 'manager']), $item['rep'], $item['roles']);
            }

            return $rows;
        });

        $last = max(1, (int) ceil($total / $size));
        $link = function (int $number) use ($request): string {
            $query = $request->query();
            $query['page']['number'] = $number;

            return $request->url().'?'.http_build_query($query);
        };

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $size,
                'total' => $total,
                'last_page' => $last,
            ],
            'links' => [
                'first' => $link(1),
                'prev' => $page > 1 ? $link($page - 1) : null,
                'next' => $page < $last ? $link($page + 1) : null,
                'last' => $link($last),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        abort_if($user->keycloak_sub === null, 404);
        $kc = $this->keycloak->getUser($user->keycloak_sub);
        $roles = $this->keycloak->realmRolesOf($user->keycloak_sub);

        return IdentityUserResource::respond($user->load(['department', 'manager']), $kc, $roles);
    }

    /**
     * @param  array<string, mixed>  $rep
     * @param  list<string>  $roles
     */
    private function mirror(array $rep, array $roles): ?User
    {
        $sub = (string) $rep['id'];
        $existing = User::query()->where('keycloak_sub', $sub)->first();
        if ($existing !== null) {
            return $existing;
        }

        $email = mb_strtolower((string) ($rep['email'] ?? ''));
        $base = Role::fromKeycloakRoles($roles);
        // users.email is NOT NULL and users.role cannot represent "no base role".
        if ($email === '' || $base === null) {
            return null;
        }

        $byEmail = User::query()->where('email', $email)->first();
        if ($byEmail !== null) {
            if ($byEmail->keycloak_sub !== null) {
                return null;
            }
            $byEmail->forceFill([
                'keycloak_sub' => $sub,
                'role' => $base,
                'is_admin' => in_array(Role::Admin->value, $roles, true),
                'name' => $this->name($rep, $byEmail->name),
            ])->save();

            return $byEmail;
        }

        $user = new User;
        $user->forceFill([
            'name' => $this->name($rep, $email),
            'email' => $email,
            'keycloak_sub' => $sub,
            'role' => $base,
            'is_admin' => in_array(Role::Admin->value, $roles, true),
            'is_active' => (bool) ($rep['enabled'] ?? true),
        ])->save();

        return $user;
    }

    /** @param  array<string, mixed>  $rep */
    private function name(array $rep, string $fallback): string
    {
        $name = trim(((string) ($rep['firstName'] ?? '')).' '.((string) ($rep['lastName'] ?? '')));

        return $name !== '' ? $name : $fallback;
    }
}
