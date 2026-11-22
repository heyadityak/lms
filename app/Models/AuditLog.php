<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\HasPublicId;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only HR audit of LMS master data. Ordinary leave transitions are not recorded here. */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use AppendOnly, HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'subject_type', 'subject_id', 'action', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
