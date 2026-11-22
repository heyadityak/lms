<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Models\UserAdminAudit;

/** One append-only `user_admin_audits` row. Never stores secrets (D-22). */
final class RecordUserAdminAudit
{
    /** @param  array<string, mixed>  $changes */
    public function write(
        User $actor,
        ?int $targetUserId,
        ?string $keycloakUserId,
        string $action,
        array $changes,
        string $outcome,
        ?string $errorCode = null,
    ): void {
        UserAdminAudit::query()->create([
            'actor_id' => $actor->id,
            'target_user_id' => $targetUserId,
            'keycloak_user_id' => $keycloakUserId,
            'action' => $action,
            'changes' => $changes,
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'request_id' => request()->headers->get('X-Request-Id'),
        ]);
    }
}
