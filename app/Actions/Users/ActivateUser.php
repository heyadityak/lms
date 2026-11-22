<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\RecordAuditLog;
use App\Exceptions\InvalidTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** UC-H2 reactivation. Cancelled requests stay cancelled. */
final class ActivateUser
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_active) {
                throw new InvalidTransition('User is already active.');
            }
            $locked->forceFill(['is_active' => true])->save();
            if ($actor !== null) {
                $this->audit->write($actor, 'user', $locked->public_id, 'updated', [
                    'before' => ['is_active' => false],
                    'after' => ['is_active' => true],
                ]);
            }
        });
    }
}
