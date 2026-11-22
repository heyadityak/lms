<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Role;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\UserAdminGuard;
use App\Support\Keycloak\KeycloakAdminClient;
use Illuminate\Support\Facades\DB;

/** UC-U6. One base realm role plus optional `admin`. Other realm roles are left alone. */
final class AssignRolesAction
{
    public function __construct(
        private readonly KeycloakAdminClient $keycloak,
        private readonly UserAdminGuard $guard,
        private readonly RecordUserAdminAudit $audits,
    ) {}

    /** @param  list<string>  $roles */
    public function handle(User $user, array $roles, User $actor): User
    {
        try {
            $this->guard->assertNotSelf($actor, $user);
            $base = $this->guard->baseRole($roles);
            $wantsAdmin = in_array(Role::Admin->value, $roles, true);
            if ($user->keycloak_sub === null) {
                abort(404);
            }

            return $this->guard->lock(function () use ($user, $roles, $actor, $base, $wantsAdmin): User {
                $this->guard->assertNotLastAdmin($user, $user->is_admin && ! $wantsAdmin);
                $current = $this->keycloak->realmRolesOf($user->keycloak_sub);
                $add = [];
                $remove = [];
                if (! in_array($base, $current, true)) {
                    $add[] = $base;
                }
                foreach ([Role::Employee->value, Role::Manager->value, Role::HrAdmin->value] as $name) {
                    if ($name !== $base && in_array($name, $current, true)) {
                        $remove[] = $name;
                    }
                }
                $hasAdmin = in_array(Role::Admin->value, $current, true);
                if ($wantsAdmin && ! $hasAdmin) {
                    $add[] = Role::Admin->value;
                }
                if (! $wantsAdmin && $hasAdmin) {
                    $remove[] = Role::Admin->value;
                }

                $this->keycloak->addRealmRoles($user->keycloak_sub, $add);
                $this->keycloak->removeRealmRoles($user->keycloak_sub, $remove);

                return DB::transaction(function () use ($user, $actor, $base, $wantsAdmin, $roles): User {
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $locked->forceFill(['role' => Role::from($base), 'is_admin' => $wantsAdmin])->save();
                    $this->audits->write($actor, $locked->id, $locked->keycloak_sub, 'assign_role', ['roles' => $roles], 'success');

                    return $locked;
                });
            });
        } catch (DomainException $e) {
            $this->audits->write($actor, $user->id, $user->keycloak_sub, 'assign_role', ['roles' => $roles], 'failed', $e->problemCode);
            throw $e;
        }
    }
}
