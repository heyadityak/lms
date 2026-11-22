<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = ['code', 'name'];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Holiday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
