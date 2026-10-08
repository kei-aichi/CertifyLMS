<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
            Storage::disk('public')->delete($path);
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
}
