<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accrual\GrantInitialEntitlement;
use App\Exceptions\DomainException;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * UC-H2: HR-owned (LMS) fields only. Profile fields through Keycloak join this action with `user_management` (phase 15).
 * First `date_of_joining` grants the initial entitlement (USE_CASES §3.7).
 */
final class UpdateUserAction
{
    private const MAX_CHAIN_DEPTH = 10;

    public function __construct(private readonly GrantInitialEntitlement $grant) {}

    /** @param  array{department_id?: string|null, manager_id?: string|null, date_of_joining?: string, employee_code?: string|null, designation?: string|null, employment_type?: string|null}  $data  ULIDs for ids */
    public function handle(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $firstJoiningDate = $user->date_of_joining === null && isset($data['date_of_joining']);

            if (array_key_exists('department_id', $data)) {
                $data['department_id'] = $data['department_id'] === null ? null : Department::query()->where('public_id', $data['department_id'])->value('id');
            }
            if (array_key_exists('manager_id', $data)) {
                $data['manager_id'] = $data['manager_id'] === null ? null : User::query()->where('public_id', $data['manager_id'])->value('id');
                if ($data['manager_id'] !== null && $data['manager_id'] !== $user->manager_id) {
                    $this->assertNoCycle($user, $data['manager_id']);
                }
            }

            $user->fill($data)->save();

            if ($firstJoiningDate) {
                $this->grant->handle($user);
            }

            return $user;
        });
    }

    /** USE_CASES §3.9: no self-manager, no cycle (walk up to depth 10). Serialised so two concurrent edits cannot close a loop. */
    private function assertNoCycle(User $user, int $managerId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['users.manager_chain']);

        for ($id = $managerId, $depth = 0; $id !== null && $depth < self::MAX_CHAIN_DEPTH; $depth++) {
            if ($id === $user->id) {
                throw new DomainException('A user cannot (indirectly) manage themselves.', 'MANAGER_CYCLE', 422, ['manager_id' => ['The manager chain would form a cycle.']]);
            }
            $id = User::query()->whereKey($id)->value('manager_id');
        }
    }
}
