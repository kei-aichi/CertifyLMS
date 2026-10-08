<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StoreAvatarAction
{
    public function __invoke(User $user, UploadedFile $file): User
    {
        $path = "avatars/{$user->id}/".Str::ulid().'.'.strtolower($file->extension());

        $storedPath = Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));
        if ($storedPath === false) {
            throw new \RuntimeException('アバター画像を保存できませんでした。');
        }

        try {
            $oldUrl = $user->avatar_url;
            $updated = DB::transaction(function () use ($user, $path): User {
                $user->update(['avatar_url' => Storage::disk('public')->url($path)]);

                return $user->refresh();
            });
        } catch (\Throwable $exception) {
            $this->deleteManagedFile($path, $user, 'new_avatar_compensation');
            throw $exception;
        }

        $oldPath = $this->managedPath($oldUrl, $user);
        if ($oldPath !== null) {
            $this->deleteManagedFile($oldPath, $user, 'old_avatar_cleanup');
        }

        return $updated;
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

    private function deleteManagedFile(string $path, User $user, string $operation): void
    {
        try {
            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Failed to delete a managed avatar file.', [
                    'user_id' => $user->id,
                    'operation' => $operation,
                    'path' => $path,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to delete a managed avatar file.', [
                'user_id' => $user->id,
                'operation' => $operation,
                'path' => $path,
                'exception' => $exception,
            ]);
        }
    }
}
