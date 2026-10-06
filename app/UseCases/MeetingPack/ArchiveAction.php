<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックをアーカイブ（published → archived）。二重操作も状態競合として拒否する。
 */
final class ArchiveAction
{
    /**
     * @throws MeetingPackInvalidTransitionException
     */
    public function __invoke(MeetingPack $plan, User $admin): MeetingPack
    {
        return DB::transaction(function () use ($plan, $admin) {
            // Binding 時点の状態を信用せず、競合する状態変更を直列化してから判定する。
            $plan = MeetingPack::query()->lockForUpdate()->findOrFail($plan->id);

            if ($plan->status !== MeetingPackStatus::Published) {
                throw MeetingPackInvalidTransitionException::forArchive();
            }

            $plan->update([
                'status' => MeetingPackStatus::Archived->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
