<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accrual\GrantInitialEntitlement;
use App\Enums\Role;
use App\Exceptions\DomainException;
use App\Exceptions\IdpConflict;
use App\Models\Department;
use App\Models\User;
use App\Support\Keycloak\KeycloakAdminClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * UC-U2. Keycloak first (no password), then the local row. Local failure deletes the Keycloak user.
 * `execute-actions-email` (UPDATE_PASSWORD, VERIFY_EMAIL) runs after commit.
 */
final class CreateUserAction
{
    public function __construct(
        private readonly KeycloakAdminClient $keycloak,
        private readonly GrantInitialEntitlement $grant,
        private readonly RecordUserAdminAudit $audits,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): User
    {
        $email = mb_strtolower((string) $data['email']);
        $role = (string) ($data['role'] ?? Role::Employee->value);
        $isAdmin = (bool) ($data['is_admin'] ?? false);
        $enabled = (bool) ($data['enabled'] ?? true);
        $roles = $isAdmin ? [$role, Role::Admin->value] : [$role];
        $kcId = null;

        try {
            $kcId = $this->keycloak->createUser([
                'username' => $data['username'],
                'email' => $email,
                'firstName' => $data['first_name'],
                'lastName' => $data['last_name'],
                'enabled' => $enabled,
                'emailVerified' => false,
            ]);
            $this->keycloak->addRealmRoles($kcId, $roles);

            $user = DB::transaction(function () use ($data, $actor, $email, $role, $isAdmin, $enabled, $kcId): User {
                $user = new User;
                $user->forceFill([
                    'name' => trim($data['first_name'].' '.$data['last_name']),
                    'email' => $email,
                    'keycloak_sub' => $kcId,
                    'role' => Role::from($role),
                    'is_admin' => $isAdmin,
                    'is_active' => $enabled,
                    'department_id' => $this->localId(Department::class, $data['department_id'] ?? null),
                    'manager_id' => $this->localId(User::class, $data['manager_id'] ?? null),
                    'date_of_joining' => $data['date_of_joining'] ?? null,
                    'employee_code' => $data['employee_code'] ?? null,
                    'designation' => $data['designation'] ?? null,
                    'employment_type' => $data['employment_type'] ?? null,
                ])->save();

                if ($user->date_of_joining !== null) {
                    $this->grant->handle($user);
                }

                $this->audits->write($actor, $user->id, $kcId, 'create', [
                    'username' => $data['username'],
                    'email' => $email,
                    'role' => $role,
                    'is_admin' => $isAdmin,
                ], 'success');

                return $user;
            });
        } catch (DomainException $e) {
            if ($kcId !== null) {
                $this->compensate($actor, $kcId);
            }
            $this->audits->write($actor, null, $kcId, 'create', ['email' => $email], 'failed', $e->problemCode);
            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            if ($kcId !== null) {
                $this->compensate($actor, $kcId);
            }
            $this->audits->write($actor, null, $kcId, 'create', ['email' => $email], 'failed', 'IDP_CONFLICT');
            throw new IdpConflict(previous: $e);
        } catch (Throwable $e) {
            if ($kcId !== null) {
                $this->compensate($actor, $kcId);
            }
            $this->audits->write($actor, null, $kcId, 'create', ['email' => $email], 'failed', 'INTERNAL_ERROR');
            throw $e;
        }

        try {
            $this->keycloak->executeActionsEmail($kcId, ['UPDATE_PASSWORD', 'VERIFY_EMAIL']);
        } catch (Throwable $e) {
            Log::warning('identity.actions_email_failed', ['keycloak_user_id' => $kcId, 'message' => $e->getMessage()]);
        }

        return $user;
    }

    private function compensate(User $actor, string $kcId): void
    {
        try {
            $this->keycloak->deleteUser($kcId);
            $this->audits->write($actor, null, $kcId, 'compensate_delete', [], 'success');
        } catch (Throwable $e) {
            $code = $e instanceof DomainException ? $e->problemCode : 'IDP_UNAVAILABLE';
            $this->audits->write($actor, null, $kcId, 'compensate_delete', [], 'failed', $code);
        }
    }

    /** @param  class-string<Department|User>  $model */
    private function localId(string $model, mixed $publicId): ?int
    {
        if ($publicId === null) {
            return null;
        }
        $id = $model::query()->where('public_id', $publicId)->value('id');
        if ($id === null) {
            throw new DomainException('That related record was not found.', 'VALIDATION_FAILED', 422);
        }

        return (int) $id;
    }
}
