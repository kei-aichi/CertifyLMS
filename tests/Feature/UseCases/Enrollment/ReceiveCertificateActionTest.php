<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Enrollment\CompletionNotEligibleException;
use App\Exceptions\Enrollment\EnrollmentNotLearningException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\UseCases\Enrollment\ReceiveCertificateAction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Mockery;
use Tests\TestCase;

/**
 * 受講生による「修了証を受け取る」自己発火 Action のロジックを直接検証する。
 * Controller 経由の認可は別途 EnrollmentControllerTest で検証する。
 */
class ReceiveCertificateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_successfully_issues_certificate_and_records_status_log_when_all_published_exams_passed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);

        $certificate = app(ReceiveCertificateAction::class)($enrollment);

        $this->assertSame($enrollment->id, $certificate->enrollment_id);
        $this->assertSame($student->id, $certificate->user_id);
        Storage::disk('private')->assertExists($certificate->pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('private')->get($certificate->pdf_path));
        $this->assertGreaterThan(0, Storage::disk('private')->size($certificate->pdf_path));
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'status' => EnrollmentStatus::Passed->value,
        ]);
        $this->assertNotNull($enrollment->refresh()->passed_at);
        $this->assertDatabaseHas('enrollment_status_logs', [
            'enrollment_id' => $enrollment->id,
            'from_status' => EnrollmentStatus::Learning->value,
            'to_status' => EnrollmentStatus::Passed->value,
            'changed_by_user_id' => $student->id,
            'changed_reason' => '受講生による修了証受領',
        ]);
    }

    public function test_throws_when_not_eligible_due_to_unpassed_exam(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam1 = MockExam::factory()->for($certification)->create(['is_published' => true]);
        $exam2 = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam1)->create(['pass' => true]);
        // exam2 は合格してない

        $this->expectException(CompletionNotEligibleException::class);

        app(ReceiveCertificateAction::class)($enrollment);

        // 念のため: Enrollment は learning のまま、Certificate も作成されないことを確認
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->refresh()->status);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_throws_when_enrollment_status_is_already_passed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();

        $this->expectException(EnrollmentNotLearningException::class);

        app(ReceiveCertificateAction::class)($enrollment);
    }

    public function test_throws_when_enrollment_status_is_failed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->failed()->create();

        $this->expectException(EnrollmentNotLearningException::class);

        app(ReceiveCertificateAction::class)($enrollment);
    }

    public function test_no_certificate_is_created_when_published_exam_count_is_zero(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        // 公開模試 0 件 → eligibility false

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('CompletionNotEligibleException が throw されるはず');
        } catch (CompletionNotEligibleException) {
            // 期待通り
        }

        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->refresh()->status);
    }

    public function test_rolls_back_when_pdf_generation_fails(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);

        View::shouldReceive('make')->andThrow(new \RuntimeException('PDF rendering failed'));

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('PDF rendering exception was expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('PDF rendering failed', $exception->getMessage());
        }

        $this->assertSame(EnrollmentStatus::Learning, $enrollment->refresh()->status);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('enrollment_status_logs', 0);
    }

    public function test_rolls_back_when_pdf_storage_fails(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('PDF storage exception was expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('修了証PDFを保存できませんでした。', $exception->getMessage());
        }

        $this->assertSame(EnrollmentStatus::Learning, $enrollment->refresh()->status);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('enrollment_status_logs', 0);
    }
}
