<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = ['date', 'name', 'location_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
