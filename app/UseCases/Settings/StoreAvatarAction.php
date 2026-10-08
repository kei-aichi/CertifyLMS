<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StoreAvatarAction
{
    public function __invoke(User $user, UploadedFile $file): User
    {
        $path = "avatars/{$user->id}/".Str::ulid().'.'.strtolower($file->extension());
        try {
            Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));
            $oldUrl = $user->avatar_url;
            $updated = DB::transaction(function () use ($user, $path): User {
                $user->update(['avatar_url' => Storage::disk('public')->url($path)]);

                return $user->refresh();
            });
            $oldPath = $this->managedPath($oldUrl, $user);
            if ($oldPath !== null) {
                Storage::disk('public')->delete($oldPath);
            }

            return $updated;
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }

    private function managedPath(?string $url, User $user): ?string
    {
        if ($url === null) {
            return null;
        }
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = str_starts_with($path, 'storage/') ? substr($path, 8) : $path;

        return str_starts_with($path, "avatars/{$user->id}/") ? $path : null;
    }
}
