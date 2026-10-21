<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_student_can_download_own_certificate_after_graduation(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certificate = $this->createCertificate($student);

        $response = $this->actingAs($student)->get(route('certificates.download', $certificate));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="'.basename($certificate->pdf_path).'"');
    }

    public function test_student_can_download_own_certificate_while_learning(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->createCertificate($student, 'learning');

        $this->actingAs($student)
            ->get(route('certificates.download', $certificate))
            ->assertOk();
    }

    public function test_student_cannot_download_another_students_certificate(): void
    {
        $owner = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $certificate = $this->createCertificate($owner);

        $this->actingAs($otherStudent)
            ->get(route('certificates.download', $certificate))
            ->assertForbidden();
    }

    public function test_assigned_coach_can_download_certificate(): void
    {
        $coach = User::factory()->coach()->create();
        $certificate = $this->createCertificate();
        $certificate->certification->coaches()->attach($coach->id, [
            'id' => (string) str()->ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->get(route('certificates.download', $certificate))
            ->assertOk();
    }

    public function test_unassigned_coach_cannot_download_certificate(): void
    {
        $coach = User::factory()->coach()->create();
        $certificate = $this->createCertificate();

        $this->actingAs($coach)
            ->get(route('certificates.download', $certificate))
            ->assertForbidden();
    }

    public function test_unassigned_coach_cannot_download_after_assignment_is_ended(): void
    {
        $coach = User::factory()->coach()->create();
        $certificate = $this->createCertificate();
        $certificate->certification->coaches()->attach($coach->id, [
            'id' => (string) str()->ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => now(),
        ]);

        $this->actingAs($coach)
            ->get(route('certificates.download', $certificate))
            ->assertForbidden();
    }

    public function test_admin_can_download_any_certificate(): void
    {
        $admin = User::factory()->admin()->create();
        $certificate = $this->createCertificate();

        $this->actingAs($admin)
            ->get(route('certificates.download', $certificate))
            ->assertOk();
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $certificate = $this->createCertificate();

        $this->get(route('certificates.download', $certificate))
            ->assertRedirect(route('login'));
    }

    public function test_missing_pdf_returns_not_found_without_regenerating(): void
    {
        $student = User::factory()->student()->create();
        $certificate = Certificate::factory()->forEnrollment(
            Enrollment::factory()->for($student)->passed()->create(),
        )->create();

        $this->actingAs($student)
            ->get(route('certificates.download', $certificate))
            ->assertNotFound();

        Storage::disk('private')->assertMissing($certificate->pdf_path);
    }

    /** @dataProvider invalidPdfPathProvider */
    public function test_invalid_pdf_path_returns_not_found(string $invalidPath): void
    {
        $student = User::factory()->student()->create();
        $certificate = $this->createCertificate($student);
        $certificate->update(['pdf_path' => $invalidPath]);

        $this->actingAs($student)
            ->get(route('certificates.download', $certificate))
            ->assertNotFound();
    }

    public static function invalidPdfPathProvider(): array
    {
        return [
            ['../outside.pdf'],
            ['/tmp/outside.pdf'],
            ['certificates/../outside.pdf'],
            ['other/sample.pdf'],
        ];
    }

    public function test_download_response_body_matches_private_pdf_binary(): void
    {
        $student = User::factory()->student()->create();
        $certificate = $this->createCertificate($student);
        $binary = Storage::disk('private')->get($certificate->pdf_path);

        $response = $this->actingAs($student)
            ->get(route('certificates.download', $certificate));

        $this->assertSame($binary, $response->getContent());
    }

    private function createCertificate(?User $student = null, string $enrollmentState = 'passed'): Certificate
    {
        $student ??= User::factory()->student()->create();
        $enrollmentFactory = Enrollment::factory()->for($student);
        $enrollment = $enrollmentState === 'learning'
            ? $enrollmentFactory->learning()->create()
            : $enrollmentFactory->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        Storage::disk('private')->put($certificate->pdf_path, '%PDF-test');

        return $certificate->load('certification');
    }
}
