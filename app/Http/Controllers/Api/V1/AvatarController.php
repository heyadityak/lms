<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Users\DeleteAvatar;
use App\Actions\Users\StoreAvatar;
use App\Http\Requests\StoreAvatarRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AvatarController
{
    public function store(StoreAvatarRequest $request, StoreAvatar $store): Response
    {
        $store->handle($request->user(), $request->file('file'));

        return response()->noContent();
    }

    public function destroy(DeleteAvatar $delete): Response
    {
        $user = request()->user();
        if (! is_string($user->avatar_path) || $user->avatar_path === '') {
            abort(404);
        }
        $delete->handle($user);

        return response()->noContent();
    }

    public function show(User $user): StreamedResponse
    {
        $path = $user->avatar_path;
        if (! is_string($path) || $path === '' || ! Storage::disk('s3')->exists($path)) {
            abort(404);
        }

        return Storage::disk('s3')->response($path);
    }
}
