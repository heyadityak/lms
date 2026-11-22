<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\User;

/** One append-only audit_logs row for HR master data. Not used for ordinary leave transitions. */
final class RecordAuditLog
{
    /** @param  array<string, mixed>  $changes */
    public function write(User $actor, string $subjectType, string $subjectId, string $action, array $changes = []): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actor->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'changes' => $changes,
        ]);
    }
}
