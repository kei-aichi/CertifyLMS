<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateProfileAction
{
    /** @param array{name: string, bio?: ?string, meeting_url?: ?string} $validated */
    public function __invoke(User $user, array $validated): User
    {
        return DB::transaction(function () use ($user, $validated): User {
            $attributes = [
                'name' => $validated['name'],
                'bio' => $validated['bio'] ?? null,
            ];

            if ($user->role === UserRole::Coach && array_key_exists('meeting_url', $validated)) {
                $attributes['meeting_url'] = $validated['meeting_url'] ?: null;
            }

            $user->update($attributes);

            return $user->refresh();
        });
    }
}
