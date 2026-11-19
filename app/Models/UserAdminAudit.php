<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserAdminAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per identity-admin action, success or failure (D-22). Append-only; never stores secrets. */
class UserAdminAudit extends Model
{
    /** @use HasFactory<UserAdminAuditFactory> */
    use AppendOnly, HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'target_user_id', 'keycloak_user_id', 'action', 'changes', 'outcome', 'error_code', 'request_id'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
