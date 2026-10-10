<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

class EnrollmentNotePolicy
{
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        return $this->canAccessEnrollment($user, $enrollment)
            && $this->isAdminOrAssignedCoach($user, $enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $this->canAccessEnrollment($user, $enrollment)
            && $this->isAdminOrAssignedCoach($user, $enrollment);
    }

    public function update(User $user, EnrollmentNote $note): bool
    {
        $enrollment = $this->enrollmentForNote($note);

        return $enrollment !== null
            && $this->canAccessEnrollment($user, $enrollment)
            && ($user->role === UserRole::Admin
                || ($this->isAssignedCoach($user, $enrollment) && $note->author_user_id === $user->id));
    }

    public function delete(User $user, EnrollmentNote $note): bool
    {
        return $this->update($user, $note);
    }

    private function canAccessEnrollment(User $user, Enrollment $enrollment): bool
    {
        if (! in_array($user->role, [UserRole::Admin, UserRole::Coach], true)) {
            return false;
        }

        if (! $enrollment->relationLoaded('user')) {
            $enrollment->load(['user' => fn ($query) => $query->withTrashed()]);
        }

        return $enrollment->deleted_at === null
            && $enrollment->user !== null
            && $enrollment->user->deleted_at === null;
    }

    private function isAdminOrAssignedCoach(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Admin || $this->isAssignedCoach($user, $enrollment);
    }

    private function isAssignedCoach(User $user, Enrollment $enrollment): bool
    {
        if ($user->role !== UserRole::Coach) {
            return false;
        }

        if (! $enrollment->relationLoaded('certification')) {
            $enrollment->load('certification');
        }

        $certification = $enrollment->certification;
        if ($certification !== null && ! $certification->relationLoaded('coaches')) {
            $certification->load('coaches');
        }

        return $certification instanceof Certification
            && $certification->coaches->contains('id', $user->id);
    }

    private function enrollmentForNote(EnrollmentNote $note): ?Enrollment
    {
        if ($note->relationLoaded('enrollment')) {
            return $note->enrollment;
        }

        return Enrollment::withTrashed()->find($note->enrollment_id);
    }
}
