<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Database\Seeders\CertificationCategorySeeder;
use Database\Seeders\CertificationSeeder;
use Database\Seeders\EnrollmentNoteSeeder;
use Database\Seeders\EnrollmentSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentNoteSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_notes_for_active_enrollments_with_valid_authors(): void
    {
        $this->seed([UserSeeder::class, CertificationCategorySeeder::class, CertificationSeeder::class, EnrollmentSeeder::class]);

        $this->seed(EnrollmentNoteSeeder::class);

        $notes = EnrollmentNote::query()->with(['enrollment.user', 'author'])->get();
        $this->assertGreaterThanOrEqual(2, $notes->pluck('enrollment.user_id')->unique()->count());
        $this->assertGreaterThanOrEqual(2, $notes->pluck('enrollment.certification_id')->unique()->count());
        $this->assertGreaterThanOrEqual(2, $notes->where('author.role', UserRole::Coach)->pluck('author_user_id')->unique()->count());
        $this->assertGreaterThan(0, $notes->where('author.role', UserRole::Admin)->count());
        $this->assertGreaterThanOrEqual(3, $notes->pluck('created_at')->unique()->count());
        $this->assertTrue($notes->every(fn (EnrollmentNote $note): bool => $note->enrollment !== null
            && $note->enrollment->deleted_at === null
            && $note->enrollment->user?->deleted_at === null
            && in_array($note->author?->role, [UserRole::Coach, UserRole::Admin], true)
        ));

        $sameEnrollmentCoachIds = $notes->groupBy('enrollment_id')
            ->map(fn ($group) => $group->where('author.role', UserRole::Coach)->pluck('author_user_id')->unique()->count())
            ->max();
        $this->assertGreaterThanOrEqual(2, $sameEnrollmentCoachIds);
    }

    public function test_re_running_seeder_does_not_duplicate_notes_or_modify_existing_notes(): void
    {
        $this->seed([UserSeeder::class, CertificationCategorySeeder::class, CertificationSeeder::class, EnrollmentSeeder::class]);
        $this->seed(EnrollmentNoteSeeder::class);
        $before = EnrollmentNote::query()->pluck('body', 'id')->all();
        $count = EnrollmentNote::query()->count();
        $enrollment = Enrollment::query()->whereHas('notes')->firstOrFail();
        $author = User::query()->where('role', UserRole::Admin->value)->firstOrFail();
        $existing = EnrollmentNote::factory()->forEnrollment($enrollment)->by($author)->create([
            'body' => '既存メモ（Seeder対象外）',
        ]);
        $count++;

        $this->seed(EnrollmentNoteSeeder::class);

        $this->assertSame($count, EnrollmentNote::query()->count());
        $this->assertSame('既存メモ（Seeder対象外）', $existing->fresh()->body);
        $managedBefore = collect($before)->filter(fn ($body): bool => str_starts_with($body, '[S-B-07 seed]'));
        $this->assertSame($managedBefore->all(), EnrollmentNote::query()->where('body', 'like', '[S-B-07 seed]%')->pluck('body', 'id')->all());
    }
}
