<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\UserAdminGuard;
use App\Support\Keycloak\KeycloakAdminClient;
use Illuminate\Support\Facades\DB;

/** UC-U4. Disable also logs the user out of Keycloak and cancels their pending leave (DeactivateUser). */
final class SetUserEnabledAction
{
    public function __construct(
        private readonly KeycloakAdminClient $keycloak,
        private readonly UserAdminGuard $guard,
        private readonly DeactivateUser $deactivate,
        private readonly ActivateUser $activate,
        private readonly RecordUserAdminAudit $audits,
    ) {}

    public function handle(User $user, bool $enabled, User $actor): User
    {
        $action = $enabled ? 'enable' : 'disable';

        try {
            if (! $enabled) {
                $this->guard->assertNotSelf($actor, $user);
            }
            if ($user->keycloak_sub === null) {
                abort(404);
            }

            return $this->guard->lock(function () use ($user, $enabled, $actor, $action): User {
                if (! $enabled) {
                    $this->guard->assertNotLastAdmin($user, true);
                }
                $this->keycloak->setEnabled($user->keycloak_sub, $enabled);
                if (! $enabled) {
                    $this->keycloak->logoutUser($user->keycloak_sub);
                }

                return DB::transaction(function () use ($user, $enabled, $actor, $action): User {
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    if (! $enabled && $locked->is_active) {
                        $this->deactivate->handle($locked, $actor);
                    }
                    if ($enabled && ! $locked->is_active) {
                        $this->activate->handle($locked);
                    }
                    $fresh = $locked->refresh();
                    $this->audits->write($actor, $fresh->id, $fresh->keycloak_sub, $action, ['enabled' => $enabled], 'success');

                    return $fresh;
                });
            });
        } catch (DomainException $e) {
            $this->audits->write($actor, $user->id, $user->keycloak_sub, $action, ['enabled' => $enabled], 'failed', $e->problemCode);
            throw $e;
        }
    }
}
