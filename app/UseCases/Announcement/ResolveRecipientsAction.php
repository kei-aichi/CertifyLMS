<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ResolveRecipientsAction
{
    /**
     * @return Collection<int, User>
     */
    public function __invoke(
        AnnouncementTargetType $targetType,
        ?string $targetCertificationId = null,
        ?string $targetUserId = null,
    ): Collection {
        $query = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value);

        return match ($targetType) {
            AnnouncementTargetType::AllStudents => $query->get(),
            AnnouncementTargetType::Certification => $query
                ->whereHas('enrollments', function ($enrollments) use ($targetCertificationId): void {
                    $enrollments
                        ->where('certification_id', $targetCertificationId)
                        ->where('status', EnrollmentStatus::Learning->value);
                })
                ->get(),
            AnnouncementTargetType::User => $query
                ->whereKey($targetUserId)
                ->get(),
        };
    }
}
