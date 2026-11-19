<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** Never hard-deleted (D-12): deactivate via is_active. Credentials live in Keycloak (D-20). */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId, Notifiable;

    /** No remember_token column: sessions come from Keycloak SSO. */
    protected $rememberTokenName = '';

    /** DB defaults, so new models match the row before refresh(). */
    protected $attributes = [
        'role' => 'employee',
        'is_admin' => false,
        'is_active' => true,
    ];

    /**
     * Mass assignable. Identity columns (keycloak_sub, role, is_admin) are written only by KeycloakUserProvisioner
     * and the user-admin actions via forceFill (Keycloak is the authority, D-20); is_active only by DeactivateUser.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'department_id',
        'manager_id',
        'employee_code',
        'designation',
        'employment_type',
        'date_of_joining',
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'date_of_joining' => 'date',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return HasMany<User, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** @return HasMany<LeaveLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }
}
