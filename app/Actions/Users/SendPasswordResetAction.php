<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\UserAdminGuard;
use App\Support\Keycloak\KeycloakAdminClient;

/** UC-U5. Keycloak sends UPDATE_PASSWORD. Federated users are refused (their password lives in the directory). */
final class SendPasswordResetAction
{
    public function __construct(
        private readonly KeycloakAdminClient $keycloak,
        private readonly UserAdminGuard $guard,
        private readonly RecordUserAdminAudit $audits,
    ) {}

    public function handle(User $user, User $actor): void
    {
        try {
            if ($user->keycloak_sub === null) {
                abort(404);
            }
            $kc = $this->keycloak->getUser($user->keycloak_sub);
            $this->guard->assertProfileEditable($kc);
            $this->keycloak->executeActionsEmail($user->keycloak_sub, ['UPDATE_PASSWORD']);
            $this->audits->write($actor, $user->id, $user->keycloak_sub, 'password_reset', [], 'success');
        } catch (DomainException $e) {
            $this->audits->write($actor, $user->id, $user->keycloak_sub, 'password_reset', [], 'failed', $e->problemCode);
            throw $e;
        }
    }
}
