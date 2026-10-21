<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\User;
use App\Services\CertificatePdfService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class CertificatePdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_and_stores_a_non_empty_pdf_on_private_disk(): void
    {
        Storage::fake('private');
        $certificate = Certificate::factory()->create([
            'user_id' => $certificateUser = User::factory()->student()->create(['name' => '山田太郎']),
            'certification_id' => $certification = Certification::factory()->published()->create(['name' => '日本語資格']),
            'pdf_path' => 'certificates/test-certificate.pdf',
        ]);

        $path = app(CertificatePdfService::class)->generateAndStore($certificate);

        $this->assertSame($certificate->pdf_path, $path);
        Storage::disk('private')->assertExists($path);
        $binary = Storage::disk('private')->get($path);
        $this->assertGreaterThan(0, strlen($binary));
        $this->assertStringStartsWith('%PDF-', $binary);
        $this->assertSame($certificateUser->id, $certificate->user_id);
        $this->assertSame($certification->id, $certificate->certification_id);
    }

    public function test_does_not_change_certificate_database_state_when_rendering_fails(): void
    {
        Storage::fake('private');
        $certificate = Certificate::factory()->create([
            'pdf_path' => 'certificates/render-failure.pdf',
        ]);
        $certificate->user()->delete();

        $this->expectException(\Throwable::class);

        try {
            app(CertificatePdfService::class)->generateAndStore($certificate->fresh());
        } finally {
            $this->assertDatabaseHas('certificates', ['id' => $certificate->id]);
            Storage::disk('private')->assertMissing($certificate->pdf_path);
        }
    }

    public function test_throws_when_storage_put_fails(): void
    {
        $certificate = Certificate::factory()->create([
            'pdf_path' => 'certificates/storage-failure.pdf',
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($certificate->pdf_path)->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->with($certificate->pdf_path)->andReturn(true);
        Storage::shouldReceive('disk')->once()->with('private')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        app(CertificatePdfService::class)->generateAndStore($certificate);
    }

    public function test_removes_file_when_post_save_validation_fails(): void
    {
        $certificate = Certificate::factory()->create([
            'pdf_path' => 'certificates/validation-failure.pdf',
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($certificate->pdf_path)->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('exists')->once()->with($certificate->pdf_path)->andReturn(true);
        $disk->shouldReceive('size')->once()->andReturn(0);
        $disk->shouldReceive('delete')->once()->with($certificate->pdf_path)->andReturn(true);
        Storage::shouldReceive('disk')->once()->with('private')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        try {
            app(CertificatePdfService::class)->generateAndStore($certificate);
        } finally {
            Mockery::close();
        }
    }

    public function test_does_not_overwrite_or_delete_an_existing_file(): void
    {
        $certificate = Certificate::factory()->create([
            'pdf_path' => 'certificates/existing.pdf',
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($certificate->pdf_path)->andReturn(true);
        $disk->shouldNotReceive('put');
        $disk->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->once()->with('private')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        app(CertificatePdfService::class)->generateAndStore($certificate);
    }

    public function test_logs_when_compensation_delete_returns_false(): void
    {
        $certificate = Certificate::factory()->create([
            'pdf_path' => 'certificates/delete-failure.pdf',
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('size')->once()->andReturn(0);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('private')->andReturn($disk);
        Log::shouldReceive('warning')->once()->with('修了証PDFの補償削除に失敗しました。');

        $this->expectException(\RuntimeException::class);
        app(CertificatePdfService::class)->generateAndStore($certificate);
    }

    public function test_logs_when_compensation_delete_throws(): void
    {
        $certificate = Certificate::factory()->create([
            'pdf_path' => 'certificates/delete-exception.pdf',
        ]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('size')->once()->andReturn(0);
        $disk->shouldReceive('delete')->once()->andThrow(new \RuntimeException('delete failed'));
        Storage::shouldReceive('disk')->once()->with('private')->andReturn($disk);
        Log::shouldReceive('warning')->once()->with('修了証PDFの補償削除に失敗しました。');

        $this->expectException(\RuntimeException::class);
        app(CertificatePdfService::class)->generateAndStore($certificate);
    }
}
