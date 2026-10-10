<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AnnouncementDispatchStatus;
use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_and_casts_are_configured(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $announcement = Announcement::factory()->create([
            'created_by' => $admin->id,
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
            'target_user_id' => $student->id,
            'dispatch_status' => AnnouncementDispatchStatus::Succeeded->value,
        ]);

        $fresh = $announcement->fresh();

        $this->assertTrue($fresh->createdBy->is($admin));
        $this->assertTrue($fresh->targetUser->is($student));
        $this->assertTrue($fresh->targetCertification->is($certification));
        $this->assertTrue($fresh->certification->is($certification));
        $this->assertSame(AnnouncementTargetType::Certification, $fresh->target_type);
        $this->assertSame(AnnouncementDispatchStatus::Succeeded, $fresh->dispatch_status);
        $this->assertIsInt($fresh->dispatched_count);
        $this->assertInstanceOf(Carbon::class, $fresh->dispatched_at);
    }

    public function test_submission_key_is_unique(): void
    {
        $announcement = Announcement::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Announcement::factory()->create([
            'submission_key' => $announcement->submission_key,
        ]);
    }

    public function test_dispatch_status_factory_states_are_available(): void
    {
        $this->assertSame(
            AnnouncementDispatchStatus::Succeeded,
            Announcement::factory()->succeeded()->create()->dispatch_status,
        );
        $this->assertSame(
            AnnouncementDispatchStatus::Failed,
            Announcement::factory()->failed()->create()->dispatch_status,
        );
    }

    public function test_id_is_a_valid_ulid(): void
    {
        $announcement = Announcement::factory()->create();

        $this->assertTrue(Str::isUlid($announcement->id));
    }

    public function test_database_defaults_are_used_when_optional_defaults_are_omitted(): void
    {
        $admin = User::factory()->admin()->create();
        $id = (string) Str::ulid();
        $now = now();

        DB::table('announcements')->insert([
            'id' => $id,
            'created_by' => $admin->id,
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'title' => 'DB default test',
            'body' => 'DB default test body',
            'submission_key' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $announcement = Announcement::query()->findOrFail($id);

        $this->assertSame(AnnouncementDispatchStatus::Processing, $announcement->dispatch_status);
        $this->assertSame(0, $announcement->dispatched_count);
        $this->assertNull($announcement->target_certification_id);
        $this->assertNull($announcement->target_user_id);
    }

    public function test_created_by_is_required(): void
    {
        $this->expectException(QueryException::class);

        DB::table('announcements')->insert([
            'id' => (string) Str::ulid(),
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'title' => 'Required created by test',
            'body' => 'Required created by test body',
            'submission_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_foreign_keys_reject_unknown_references(): void
    {
        $this->expectException(QueryException::class);

        DB::table('announcements')->insert([
            'id' => (string) Str::ulid(),
            'created_by' => (string) Str::ulid(),
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'title' => 'Foreign key test',
            'body' => 'Foreign key test body',
            'submission_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_foreign_key_delete_restrictions_preserve_announcement_history(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        Announcement::factory()->create([
            'created_by' => $admin->id,
            'target_user_id' => $student->id,
            'target_certification_id' => $certification->id,
        ]);

        try {
            $admin->forceDelete();
            $this->fail('created_by の削除が制約で拒否されるはずです。');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $admin->id]);
        }

        try {
            $student->forceDelete();
            $this->fail('target_user_id の削除が制約で拒否されるはずです。');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $student->id]);
        }

        try {
            $certification->delete();
            $this->fail('target_certification_id の削除が制約で拒否されるはずです。');
        } catch (QueryException) {
            $this->assertDatabaseHas('certifications', ['id' => $certification->id]);
        }
    }
}
