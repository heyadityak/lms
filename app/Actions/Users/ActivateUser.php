<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\InvalidTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** UC-H2 reactivation. Cancelled requests stay cancelled. */
final class ActivateUser
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_active) {
                throw new InvalidTransition('User is already active.');
            }
            $locked->forceFill(['is_active' => true])->save();
        });
    }
}
