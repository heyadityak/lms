<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/** ULID `public_id` exposed as the API `id` and route key; the bigint `id` stays internal (D-21). */
trait HasPublicId
{
    use HasUlids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** Uppercase Crockford ULID as in openapi.yaml `Ulid` (Laravel's default is lowercase). */
    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
