<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateProfileAction
{
    /** @param array{name: string, bio?: ?string} $validated */
    public function __invoke(User $user, array $validated): User
    {
        return DB::transaction(function () use ($user, $validated): User {
            $user->update([
                'name' => $validated['name'],
                'bio' => $validated['bio'] ?? null,
            ]);

            return $user->refresh();
        });
    }
}
