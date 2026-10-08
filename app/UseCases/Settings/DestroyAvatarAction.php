<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class DestroyAvatarAction
{
    public function __invoke(User $user): User
    {
        $oldUrl = $user->avatar_url;
        $updated = DB::transaction(function () use ($user): User {
            $user->update(['avatar_url' => null]);

            return $user->refresh();
        });
        $path = $this->managedPath($oldUrl, $user);
        if ($path !== null) {
            $this->deleteManagedFile($path, $user);
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

    private function deleteManagedFile(string $path, User $user): void
    {
        try {
            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Failed to delete a managed avatar file.', [
                    'user_id' => $user->id,
                    'operation' => 'deleted_avatar_cleanup',
                    'path' => $path,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to delete a managed avatar file.', [
                'user_id' => $user->id,
                'operation' => 'deleted_avatar_cleanup',
                'path' => $path,
                'exception' => $exception,
            ]);
        }
    }
}
