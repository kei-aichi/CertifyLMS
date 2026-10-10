<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementDispatchStatus;
use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\UseCases\Announcement\ResolveRecipientsAction;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * 開発・検証用のお知らせ履歴とDatabase通知を投入する。
 *
 * 実際の配信処理は呼び出さず、メールも送信しない。
 */
final class AnnouncementSeeder extends Seeder
{
    private const EMPTY_TARGET_CERTIFICATION_NAME = '【お知らせSeeder専用】対象者0人確認用資格';

    private const EMPTY_TARGET_USER_EMAIL = 'announcement-seeder-empty-target@example.com';

    public function run(): void
    {
        $admin = User::query()->where('role', UserRole::Admin->value)->first()
            ?? User::factory()->admin()->create();
        $allStudent = $this->activeStudent();
        $certification = $this->certificationWithLearningEnrollment($allStudent);
        $userStudent = $this->activeStudent();
        $emptyCertification = $this->certificationWithoutLearningEnrollment();
        $this->ensureDeletedEnrollment($emptyCertification);

        $this->seedAnnouncement(
            $admin,
            AnnouncementTargetType::AllStudents,
            null,
            null,
            '【確認用】全受講生へのお知らせ',
            '全受講生向けのお知らせ配信履歴です。',
            'seed-announcement-all',
        );
        $this->seedAnnouncement(
            $admin,
            AnnouncementTargetType::Certification,
            $certification,
            null,
            '【確認用】資格指定のお知らせ',
            '資格指定配信のお知らせ履歴です。',
            'seed-announcement-certification',
        );
        $this->seedAnnouncement(
            $admin,
            AnnouncementTargetType::User,
            null,
            $userStudent,
            '【確認用】受講生指定のお知らせ',
            '受講生指定配信のお知らせ履歴です。',
            'seed-announcement-user',
        );
        $this->seedAnnouncement(
            $admin,
            AnnouncementTargetType::Certification,
            $emptyCertification,
            null,
            '【確認用】対象者0人のお知らせ',
            '対象者0人の配信履歴です。',
            'seed-announcement-empty',
        );
    }

    private function seedAnnouncement(
        User $admin,
        AnnouncementTargetType $targetType,
        ?Certification $certification,
        ?User $user,
        string $title,
        string $body,
        string $submissionKey,
    ): void {
        $recipients = app(ResolveRecipientsAction::class)->query(
            $targetType,
            $certification?->id,
            $user?->id,
        );
        $recipientCount = (clone $recipients)->count();

        DB::transaction(function () use (
            $admin,
            $targetType,
            $certification,
            $user,
            $title,
            $body,
            $submissionKey,
            $recipientCount,
            $recipients,
        ): void {
            $announcement = Announcement::query()->firstOrCreate(
                ['submission_key' => $submissionKey],
                [
                    'created_by' => $admin->id,
                    'target_type' => $targetType,
                    'target_certification_id' => $certification?->id,
                    'target_user_id' => $user?->id,
                    'title' => $title,
                    'body' => $body,
                    'dispatched_count' => $recipientCount,
                    'dispatch_status' => AnnouncementDispatchStatus::Succeeded,
                    'dispatched_at' => now(),
                ],
            );

            foreach ($recipients->get() as $recipient) {
                $data = (new AnnouncementNotification($announcement))->toArray($recipient);
                DatabaseNotification::query()->updateOrCreate(
                    ['id' => $this->notificationId($announcement, $recipient)],
                    [
                        'type' => AnnouncementNotification::class,
                        'notifiable_type' => $recipient::class,
                        'notifiable_id' => $recipient->id,
                        'data' => $data,
                        'read_at' => null,
                        'created_at' => $announcement->dispatched_at ?? now(),
                        'updated_at' => $announcement->dispatched_at ?? now(),
                    ],
                );
            }
        });
    }

    private function activeStudent(): User
    {
        return User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->first()
            ?? User::factory()->student()->create();
    }

    private function certificationWithLearningEnrollment(User $fallbackStudent): Certification
    {
        $certification = Certification::query()
            ->whereHas('enrollments', function ($query): void {
                $query
                    ->where('status', EnrollmentStatus::Learning->value)
                    ->whereHas('user', function ($userQuery): void {
                        $userQuery
                            ->where('role', UserRole::Student->value)
                            ->where('status', UserStatus::InProgress->value)
                            ->whereNull('deleted_at');
                    });
            })
            ->first();

        if ($certification !== null) {
            return $certification;
        }

        $certification = Certification::factory()->published()->create();
        Enrollment::factory()->for($fallbackStudent)->for($certification)->learning()->create();

        return $certification;
    }

    private function certificationWithoutLearningEnrollment(): Certification
    {
        return Certification::query()->where('name', self::EMPTY_TARGET_CERTIFICATION_NAME)->first()
            ?? Certification::factory()->published()->create([
                'name' => self::EMPTY_TARGET_CERTIFICATION_NAME,
            ]);
    }

    private function ensureDeletedEnrollment(Certification $certification): void
    {
        $emptyTargetStudent = User::query()->firstOrCreate(
            ['email' => self::EMPTY_TARGET_USER_EMAIL],
            User::factory()->student()->graduated()->raw(),
        );

        $enrollment = Enrollment::withTrashed()
            ->where('user_id', $emptyTargetStudent->id)
            ->where('certification_id', $certification->id)
            ->first();

        if ($enrollment === null) {
            $enrollment = Enrollment::factory()
                ->for($emptyTargetStudent)
                ->for($certification)
                ->learning()
                ->create();
        }

        // このSeeder専用に作成したEnrollmentだけを対象者0人の確認用として論理削除する。
        if (! $enrollment->trashed()) {
            $enrollment->delete();
        }
    }

    private function notificationId(Announcement $announcement, User $recipient): string
    {
        $hex = md5($announcement->submission_key.'|'.$recipient->id);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
