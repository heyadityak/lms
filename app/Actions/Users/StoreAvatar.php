<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\UploadInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** PNG, JPEG or WebP avatar, max 1 MB, on the s3 disk. Replaces the previous object. */
final class StoreAvatar
{
    public function handle(User $user, UploadedFile $file): void
    {
        $mime = UploadInspector::mime($file, ['image/png', 'image/jpeg', 'image/webp']);
        UploadInspector::size($file, 1024 * 1024, '1 MB');

        $path = 'avatars/'.$user->public_id.'.'.UploadInspector::extension($mime);
        Storage::disk('s3')->put($path, $file->getContent());
        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();
        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('s3')->delete($previous);
        }
    }
}
