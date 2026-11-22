<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ApprovalReminderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** UC-S4 send log. One row per recipient per calendar date. Not API-exposed. */
class ApprovalReminder extends Model
{
    /** @use HasFactory<ApprovalReminderFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'reminded_on'];

    protected function casts(): array
    {
        return [
            'reminded_on' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
