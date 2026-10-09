<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_announcement_management(): void
    {
        $this->get(route('admin.announcements.index'))->assertRedirect('/login');
        $this->get(route('admin.announcements.create'))->assertRedirect('/login');
    }

    public function test_non_admin_cannot_access_announcement_management(): void
    {
        foreach ([User::factory()->student()->create(), User::factory()->coach()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.announcements.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.announcements.create'))->assertForbidden();
        }
    }

    public function test_admin_can_view_index_and_create_with_required_view_data(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $student = User::factory()->student()->create();

        $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertViewIs('announcement.management.index')
            ->assertViewHas('announcements');

        $this->actingAs($admin)
            ->get(route('admin.announcements.create'))
            ->assertOk()
            ->assertViewIs('announcement.management.create')
            ->assertViewHas('certifications', fn ($certifications) => $certifications->contains($certification))
            ->assertViewHas('students', fn ($students) => $students->contains($student));
    }

    public function test_admin_can_view_announcement_detail_with_eager_loaded_relations(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $announcement = Announcement::factory()->create([
            'created_by' => $admin->id,
            'target_user_id' => $student->id,
            'target_certification_id' => $certification->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.announcements.show', $announcement))
            ->assertOk()
            ->assertViewIs('announcement.management.show')
            ->assertViewHas('announcement', function (Announcement $viewAnnouncement) use ($admin, $student, $certification): bool {
                return $viewAnnouncement->relationLoaded('createdBy')
                    && $viewAnnouncement->relationLoaded('targetUser')
                    && $viewAnnouncement->relationLoaded('targetCertification')
                    && $viewAnnouncement->createdBy->is($admin)
                    && $viewAnnouncement->targetUser->is($student)
                    && $viewAnnouncement->targetCertification->is($certification);
            });
    }

    public function test_index_is_paginated_to_twenty_items(): void
    {
        $admin = User::factory()->admin()->create();
        Announcement::factory()->count(21)->create(['created_by' => $admin->id]);

        $firstPage = $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->viewData('announcements');
        $secondPage = $this->actingAs($admin)
            ->get(route('admin.announcements.index', ['page' => 2]))
            ->assertOk()
            ->viewData('announcements');

        $this->assertCount(20, $firstPage->getCollection());
        $this->assertCount(1, $secondPage->getCollection());
        $this->assertSame(21, $firstPage->total());
    }

    public function test_index_orders_by_created_at_descending_then_id_descending(): void
    {
        $admin = User::factory()->admin()->create();
        $sameTime = Carbon::create(2026, 10, 10, 12, 0, 0);
        $older = Announcement::factory()->create([
            'created_by' => $admin->id,
            'created_at' => $sameTime->copy()->subMinute(),
            'updated_at' => $sameTime->copy()->subMinute(),
        ]);
        $sameTimeFirst = Announcement::factory()->create([
            'created_by' => $admin->id,
            'created_at' => $sameTime,
            'updated_at' => $sameTime,
        ]);
        $sameTimeSecond = Announcement::factory()->create([
            'created_by' => $admin->id,
            'created_at' => $sameTime,
            'updated_at' => $sameTime,
        ]);
        $newer = Announcement::factory()->create([
            'created_by' => $admin->id,
            'created_at' => $sameTime->copy()->addMinute(),
            'updated_at' => $sameTime->copy()->addMinute(),
        ]);

        $announcements = $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->viewData('announcements')
            ->getCollection();

        $this->assertSame(
            [$newer->id, $sameTimeSecond->id, $sameTimeFirst->id, $older->id],
            $announcements->modelKeys(),
        );
    }

    public function test_index_eager_loads_required_relations(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        Announcement::factory()->create([
            'created_by' => $admin->id,
            'target_user_id' => $student->id,
            'target_certification_id' => $certification->id,
        ]);

        $announcements = $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->viewData('announcements')
            ->getCollection();

        $announcement = $announcements->first();
        $this->assertTrue($announcement->relationLoaded('createdBy'));
        $this->assertTrue($announcement->relationLoaded('targetUser'));
        $this->assertTrue($announcement->relationLoaded('targetCertification'));
        $this->assertFalse($announcement->relationLoaded('certification'));
    }

    public function test_create_contains_only_active_students(): void
    {
        $admin = User::factory()->admin()->create();
        $activeStudent = User::factory()->student()->create();
        $graduated = User::factory()->student()->graduated()->create();
        $withdrawn = User::factory()->student()->create(['status' => 'withdrawn']);
        $deleted = User::factory()->student()->create();
        $deleted->delete();
        $coach = User::factory()->coach()->create(['status' => 'in_progress']);
        $otherAdmin = User::factory()->admin()->create(['status' => 'in_progress']);

        $students = $this->actingAs($admin)
            ->get(route('admin.announcements.create'))
            ->assertOk()
            ->viewData('students');

        $this->assertSame([$activeStudent->id], $students->modelKeys());
        foreach ([$graduated, $withdrawn, $deleted, $coach, $otherAdmin] as $excluded) {
            $this->assertFalse($students->contains('id', $excluded->id));
        }
    }

    public function test_create_contains_all_certifications_in_name_ascending_order(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Certification::factory()->draft()->create(['name' => '資格 C']);
        $published = Certification::factory()->published()->create(['name' => '資格 A']);
        $archived = Certification::factory()->archived()->create(['name' => '資格 B']);

        $certifications = $this->actingAs($admin)
            ->get(route('admin.announcements.create'))
            ->assertOk()
            ->viewData('certifications');

        $this->assertSame(['資格 A', '資格 B', '資格 C'], $certifications->pluck('name')->all());
        $this->assertTrue($certifications->contains($draft));
        $this->assertTrue($certifications->contains($published));
        $this->assertTrue($certifications->contains($archived));
    }

    public function test_missing_announcement_returns_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.announcements.show', ['announcement' => '01j00000000000000000000000']))
            ->assertNotFound();
    }

    public function test_step_one_three_post_boundary_does_not_report_success_or_dispatch(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'))
            ->assertStatus(501);

        $this->assertDatabaseCount('announcements', 0);
    }
}
