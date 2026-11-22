<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accrual\GrantInitialEntitlement;
use App\Actions\Audit\RecordAuditLog;
use App\Exceptions\DomainException;
use App\Models\Department;
use App\Models\Location;
use App\Models\User;
use App\Services\UserAdminGuard;
use App\Support\Keycloak\KeycloakAdminClient;
use Illuminate\Support\Facades\DB;

/**
 * UC-H2 LMS fields, and UC-U3 profile fields when `$identity` is set.
 * Profile writes go to Keycloak first. A federated user is 422 IDP_READ_ONLY for those fields.
 * First `date_of_joining` grants the initial entitlement (USE_CASES §3.7).
 */
final class UpdateUserAction
{
    private const MAX_CHAIN_DEPTH = 10;

    public function __construct(
        private readonly GrantInitialEntitlement $grant,
        private readonly KeycloakAdminClient $keycloak,
        private readonly UserAdminGuard $guard,
        private readonly RecordUserAdminAudit $audits,
        private readonly RecordAuditLog $lmsAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data, ?User $actor = null, bool $identity = false): User
    {
        if (! $identity) {
            return DB::transaction(function () use ($user, $data, $actor): User {
                $before = self::snapshot($user);
                $saved = $this->persist($user, $data);
                if ($actor !== null) {
                    $this->lmsAudit->write($actor, 'user', $saved->public_id, 'updated', [
                        'before' => $before,
                        'after' => self::snapshot($saved),
                    ]);
                }

                return $saved;
            });
        }
        if ($actor === null || $user->keycloak_sub === null) {
            abort(404);
        }

        $profile = array_intersect_key($data, array_flip(['email', 'first_name', 'last_name']));
        $pushed = false;
        $kc = [];

        try {
            $kc = $this->keycloak->getUser($user->keycloak_sub);
            if ($profile !== []) {
                $this->guard->assertProfileEditable($kc);
                $patch = [];
                if (isset($data['email'])) {
                    $patch['email'] = mb_strtolower((string) $data['email']);
                }
                if (isset($data['first_name'])) {
                    $patch['firstName'] = $data['first_name'];
                }
                if (isset($data['last_name'])) {
                    $patch['lastName'] = $data['last_name'];
                }
                $this->keycloak->updateUser($user->keycloak_sub, $patch);
                $pushed = true;
                $kc = array_merge($kc, $patch);
            }

            return DB::transaction(function () use ($user, $data, $actor, $kc): User {
                if (isset($data['first_name']) || isset($data['last_name'])) {
                    $data['name'] = trim(((string) ($kc['firstName'] ?? '')).' '.((string) ($kc['lastName'] ?? '')));
                }
                if (isset($data['email'])) {
                    $data['email'] = mb_strtolower((string) $data['email']);
                }
                unset($data['first_name'], $data['last_name']);
                $saved = $this->persist($user, $data);
                $this->audits->write($actor, $saved->id, $saved->keycloak_sub, 'update_profile', ['fields' => array_keys($profile + $data)], 'success');

                return $saved;
            });
        } catch (DomainException $e) {
            if ($pushed) {
                try {
                    $this->keycloak->updateUser($user->keycloak_sub, [
                        'email' => $kc['email'] ?? null,
                        'firstName' => $kc['firstName'] ?? null,
                        'lastName' => $kc['lastName'] ?? null,
                    ]);
                } catch (DomainException) {
                    // The failed audit below is the record; Keycloak may stay ahead until the next edit.
                }
            }
            $this->audits->write($actor, $user->id, $user->keycloak_sub, 'update_profile', ['fields' => array_keys($data)], 'failed', $e->problemCode);
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function persist(User $user, array $data): User
    {
        $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $firstJoiningDate = $user->date_of_joining === null && isset($data['date_of_joining']);

        if (array_key_exists('department_id', $data)) {
            $data['department_id'] = $data['department_id'] === null ? null : Department::query()->where('public_id', $data['department_id'])->value('id');
        }
        if (array_key_exists('location_id', $data)) {
            $data['location_id'] = $data['location_id'] === null ? null : Location::query()->where('public_id', $data['location_id'])->value('id');
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
    }

    /** @return array<string, mixed> */
    private static function snapshot(User $user): array
    {
        return [
            'department_id' => $user->department_id,
            'manager_id' => $user->manager_id,
            'location_id' => $user->location_id,
            'employee_code' => $user->employee_code,
            'designation' => $user->designation,
            'employment_type' => $user->employment_type,
            'date_of_joining' => $user->date_of_joining?->toDateString(),
        ];
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
