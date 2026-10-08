<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

/**
 * EnrollmentGoalの認可。閲覧はEnrollmentの既存認可、変更は本人studentに限定する。
 */
class EnrollmentGoalPolicy
{
    public function __construct(private readonly EnrollmentPolicy $enrollmentPolicy) {}

    public function view(User $user, EnrollmentGoal $goal): bool
    {
        $enrollment = $goal->enrollment;

        return $enrollment !== null
            && $enrollment->deleted_at === null
            && $this->studentStatusAllowsAccess($user, $enrollment)
            && $this->enrollmentPolicy->view($user, $enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $this->canModify($user, $enrollment);
    }

    public function update(User $user, EnrollmentGoal $goal): bool
    {
        return $this->goalCanBeModified($user, $goal);
    }

    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->goalCanBeModified($user, $goal);
    }

    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->goalCanBeModified($user, $goal);
    }

    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->goalCanBeModified($user, $goal);
    }

    private function goalCanBeModified(User $user, EnrollmentGoal $goal): bool
    {
        $enrollment = $goal->enrollment;

        return $enrollment !== null && $this->canModify($user, $enrollment);
    }

    private function canModify(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && $enrollment->deleted_at === null
            && $enrollment->user_id === $user->id;
    }

    private function studentStatusAllowsAccess(User $user, Enrollment $enrollment): bool
    {
        return $user->role !== UserRole::Student
            || ($user->status === UserStatus::InProgress && $enrollment->user_id === $user->id);
    }
}
