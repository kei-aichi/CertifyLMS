<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\User;
use Database\Seeders\CertificateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_seeder_creates_downloadable_pdf_and_is_idempotent(): void
    {
        User::factory()->admin()->create();
        User::factory()->student()->graduated()->create();
        Certification::factory()->published()->create();

        $this->seed(CertificateSeeder::class);

        $certificate = Certificate::query()->firstOrFail();
        $firstCount = Certificate::query()->count();
        $binary = Storage::disk('private')->get($certificate->pdf_path);

        $this->assertTrue(Storage::disk('private')->exists($certificate->pdf_path));
        $this->assertGreaterThan(0, Storage::disk('private')->size($certificate->pdf_path));
        $this->assertStringStartsWith('%PDF-', $binary);

        $this->seed(CertificateSeeder::class);

        $this->assertSame($firstCount, Certificate::query()->count());
        $this->assertSame($binary, Storage::disk('private')->get($certificate->pdf_path));
    }
}
