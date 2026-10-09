<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Announcement\ResolveRecipientsAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResolveRecipientsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_returns_only_active_students_without_requiring_enrollment(): void
    {
        $target = User::factory()->student()->create();
        User::factory()->student()->graduated()->create();
        User::factory()->coach()->create(['status' => UserStatus::InProgress->value]);
        User::factory()->admin()->create(['status' => UserStatus::InProgress->value]);
        $withdrawn = User::factory()->student()->create(['status' => UserStatus::Withdrawn->value]);
        $withdrawn->delete();

        $recipients = $this->resolve(AnnouncementTargetType::AllStudents);

        $this->assertEquals([$target->id], $recipients->modelKeys());
    }

    public function test_certification_returns_only_learning_enrolled_active_students(): void
    {
        $certification = Certification::factory()->published()->create();
        $target = User::factory()->student()->create();
        Enrollment::factory()->for($target)->for($certification)->learning()->create();

        $passed = User::factory()->student()->create();
        Enrollment::factory()->for($passed)->for($certification)->passed()->create();
        $failed = User::factory()->student()->create();
        Enrollment::factory()->for($failed)->for($certification)->failed()->create();
        $graduated = User::factory()->student()->graduated()->create();
        Enrollment::factory()->for($graduated)->for($certification)->learning()->create();
        $deletedEnrollmentUser = User::factory()->student()->create();
        $deletedEnrollment = Enrollment::factory()->for($deletedEnrollmentUser)->for($certification)->learning()->create();
        $deletedEnrollment->delete();
        $deletedUser = User::factory()->student()->create();
        Enrollment::factory()->for($deletedUser)->for($certification)->learning()->create();
        $deletedUser->delete();

        $recipients = $this->resolve(AnnouncementTargetType::Certification, $certification->id);

        $this->assertEquals([$target->id], $recipients->modelKeys());
    }

    public function test_user_returns_one_active_student_without_requiring_enrollment(): void
    {
        $target = User::factory()->student()->create();

        $recipients = $this->resolve(AnnouncementTargetType::User, null, $target->id);

        $this->assertEquals([$target->id], $recipients->modelKeys());
    }

    public function test_user_excludes_non_student_non_active_and_deleted_users(): void
    {
        $coach = User::factory()->coach()->create(['status' => UserStatus::InProgress->value]);
        $graduated = User::factory()->student()->graduated()->create();
        $deleted = User::factory()->student()->create();
        $deleted->delete();

        foreach ([$coach, $graduated, $deleted] as $user) {
            $this->assertCount(0, $this->resolve(AnnouncementTargetType::User, null, $user->id));
        }
    }

    public function test_duplicate_enrollments_do_not_duplicate_a_recipient(): void
    {
        $certification = Certification::factory()->published()->create();
        $target = User::factory()->student()->create();
        Enrollment::factory()->for($target)->for($certification)->learning()->create();

        $recipients = $this->resolve(AnnouncementTargetType::Certification, $certification->id);

        // Enrollment は user_id + certification_id が一意のため、同一資格の重複行はDBで作成できない。
        // User単位の抽出結果が1件であることと、重複を生まないクエリであることを確認する。
        $this->assertCount(1, $recipients);
        $this->assertSame($target->id, $recipients->first()->id);
    }

    public function test_no_matching_recipients_returns_an_empty_collection(): void
    {
        $certification = Certification::factory()->published()->create();

        $recipients = $this->resolve(AnnouncementTargetType::Certification, $certification->id);

        $this->assertTrue($recipients->isEmpty());
    }

    /** @return Collection<int, User> */
    private function resolve(
        AnnouncementTargetType $targetType,
        ?string $certificationId = null,
        ?string $userId = null,
    ): Collection {
        return app(ResolveRecipientsAction::class)($targetType, $certificationId, $userId);
    }
}
