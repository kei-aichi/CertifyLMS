<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Enrollment;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\Exceptions\Enrollment\EnrollmentAlreadyEnrolledException;
use App\Services\AdminDashboardCacheService;
use App\UseCases\Enrollment\DestroyAction;
use App\UseCases\Enrollment\FailAction;
use App\UseCases\Enrollment\ReceiveCertificateAction;
use App\UseCases\Enrollment\ResumeAction;
use App\UseCases\Enrollment\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnrollmentCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_advances_generation_after_commit(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $cache = app(AdminDashboardCacheService::class);
        $before = $cache->version();

        app(StoreAction::class)($student, ['certification_id' => $certification->id]);

        $this->assertSame($before + 1, $cache->version());
    }

    public function test_resume_advances_generation_after_commit(): void
    {
        $enrollment = Enrollment::factory()->failed()->create();
        $actor = User::factory()->student()->inProgress()->create();
        $cache = app(AdminDashboardCacheService::class);
        $before = $cache->version();

        app(ResumeAction::class)($enrollment, $actor);

        $this->assertSame($before + 1, $cache->version());
    }

    public function test_receive_certificate_advances_generation_after_commit(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);
        $cache = app(AdminDashboardCacheService::class);
        $before = $cache->version();

        app(ReceiveCertificateAction::class)($enrollment);

        $this->assertSame($before + 1, $cache->version());
    }

    public function test_fail_advances_generation_after_commit(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $cache = app(AdminDashboardCacheService::class);
        $before = $cache->version();

        app(FailAction::class)($enrollment, $admin, 'テスト');

        $this->assertSame($before + 1, $cache->version());
    }

    public function test_destroy_advances_generation_after_commit(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $cache = app(AdminDashboardCacheService::class);
        $before = $cache->version();

        app(DestroyAction::class)($enrollment);

        $this->assertSame($before + 1, $cache->version());
    }

    public function test_nested_transaction_defers_generation_until_outer_commit(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $cache = app(AdminDashboardCacheService::class);
        $before = $cache->version();
        $inside = null;

        DB::transaction(function () use ($enrollment, $admin, $cache, &$inside): void {
            app(FailAction::class)($enrollment, $admin, 'テスト');
            $inside = $cache->version();
        });

        $this->assertSame($before, $inside);
        $this->assertSame($before + 1, $cache->version());
    }

    public function test_outer_rollback_does_not_advance_generation(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $cache = app(AdminDashboardCacheService::class);
        $before = $cache->version();

        try {
            DB::transaction(function () use ($enrollment, $admin): void {
                app(FailAction::class)($enrollment, $admin, 'テスト');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertSame($before, $cache->version());
        $this->assertSame('learning', $enrollment->fresh()->status->value);
    }

    public function test_failed_store_does_not_advance_generation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        app(StoreAction::class)($student, ['certification_id' => $certification->id]);
        $cache = app(AdminDashboardCacheService::class);
        $before = $cache->version();

        $this->expectException(EnrollmentAlreadyEnrolledException::class);
        try {
            app(StoreAction::class)($student, ['certification_id' => $certification->id]);
        } finally {
            $this->assertSame($before, $cache->version());
        }
    }
}
