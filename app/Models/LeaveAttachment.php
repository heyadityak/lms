<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\LeaveAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveAttachment extends Model
{
    /** @use HasFactory<LeaveAttachmentFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = ['leave_request_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
