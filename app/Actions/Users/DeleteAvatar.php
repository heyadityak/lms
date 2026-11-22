<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class DeleteAvatar
{
    public function handle(User $user): void
    {
        $path = $user->avatar_path;
        $user->forceFill(['avatar_path' => null])->save();
        if (is_string($path) && $path !== '') {
            Storage::disk('s3')->delete($path);
        }
    }
}
