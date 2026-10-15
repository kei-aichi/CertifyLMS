<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certification;

use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\CertificationCoachAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_certification_list(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index'));

        $response->assertOk();
        $response->assertViewIs('certification.management.index');
        $response->assertViewHas('certifications');
    }

    public function test_coach_sees_only_assigned_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assigned = Certification::factory()->published()->create(['name' => 'My Assigned Cert']);
        $other = Certification::factory()->published()->create(['name' => 'Unassigned Cert']);

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assigned->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($coach)->get(route('admin.certifications.index'));

        $response->assertOk();
        $response->assertSee('My Assigned Cert');
        $response->assertDontSee('Unassigned Cert');
    }

    public function test_student_cannot_access_admin_certifications_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.certifications.index'))
            ->assertForbidden();
    }

    public function test_keyword_filter_matches_name_only(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->create(['name' => 'TOEIC Listening']);
        Certification::factory()->published()->create(['name' => 'PMP Certification']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['keyword' => 'TOEIC']));

        $response->assertOk();
        $response->assertSee('TOEIC Listening');
        $response->assertDontSee('PMP Certification');
    }

    public function test_status_filter_returns_only_matching_status(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->draft()->create(['name' => 'Draft One']);
        Certification::factory()->published()->create(['name' => 'Published One']);
        Certification::factory()->archived()->create(['name' => 'Archived One']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['status' => 'published']));

        $response->assertOk();
        $response->assertSee('Published One');
        $response->assertDontSee('Draft One');
        $response->assertDontSee('Archived One');
    }

    public function test_admin_status_filters_return_only_the_selected_status(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->draft()->create(['name' => 'Admin Draft']);
        Certification::factory()->published()->create(['name' => 'Admin Published']);
        Certification::factory()->archived()->create(['name' => 'Admin Archived']);

        foreach (['published', 'draft', 'archived'] as $status) {
            $response = $this->actingAs($admin)->get(route('admin.certifications.index', compact('status')));

            $response->assertOk();
            $response->assertSee('Admin '.ucfirst($status));

            foreach (['published', 'draft', 'archived'] as $otherStatus) {
                if ($otherStatus !== $status) {
                    $response->assertDontSee('Admin '.ucfirst($otherStatus));
                }
            }
        }
    }

    public function test_admin_without_status_filter_sees_all_statuses(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->draft()->create(['name' => 'All Draft']);
        Certification::factory()->published()->create(['name' => 'All Published']);
        Certification::factory()->archived()->create(['name' => 'All Archived']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index'));

        $response->assertOk();
        $response->assertSee('All Draft');
        $response->assertSee('All Published');
        $response->assertSee('All Archived');
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.certifications.index', ['status' => 'invalid']))
            ->assertSessionHasErrors('status');
    }

    public function test_coach_status_filters_are_limited_to_assigned_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $statuses = ['published', 'draft', 'archived'];

        foreach ($statuses as $status) {
            $assigned = Certification::factory()->state(['status' => $status])->create([
                'name' => 'Assigned '.ucfirst($status),
            ]);
            $unassigned = Certification::factory()->state(['status' => $status])->create([
                'name' => 'Unassigned '.ucfirst($status),
            ]);

            CertificationCoachAssignment::create([
                'id' => (string) Str::ulid(),
                'certification_id' => $assigned->id,
                'user_id' => $coach->id,
                'assigned_by_user_id' => $admin->id,
                'assigned_at' => now(),
            ]);

            $response = $this->actingAs($coach)->get(route('admin.certifications.index', ['status' => $status]));

            $response->assertOk();
            $response->assertSee('Assigned '.ucfirst($status));
            $response->assertDontSee('Unassigned '.ucfirst($status));
        }
    }

    public function test_status_filter_combines_with_keyword_category_and_difficulty(): void
    {
        $admin = User::factory()->admin()->create();
        $category = CertificationCategory::factory()->create(['name' => 'Status Filter Category']);
        Certification::factory()->published()->create([
            'name' => 'Matching Published Certification',
            'category_id' => $category->id,
            'difficulty' => 'beginner',
        ]);
        Certification::factory()->draft()->create([
            'name' => 'Matching Draft Certification',
            'category_id' => $category->id,
        ]);
        Certification::factory()->published()->create(['name' => 'Other Published Certification']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', [
            'status' => 'published',
            'keyword' => 'Matching',
            'category_id' => $category->id,
            'difficulty' => 'beginner',
        ]));

        $response->assertOk();
        $response->assertSee('Matching Published Certification');
        $response->assertDontSee('Matching Draft Certification');
        $response->assertDontSee('Other Published Certification');
    }

    public function test_status_filter_is_preserved_in_pagination_links_and_status_order_is_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->count(21)->create();
        Certification::factory()->draft()->create(['name' => 'Pagination Draft']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['status' => 'published']));

        $response->assertOk();
        $certifications = $response->viewData('certifications');
        $this->assertStringContainsString('status=published', $certifications->url(2));
        $this->assertSame('published', $certifications->first()->status->value);
    }

    public function test_category_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $category = CertificationCategory::factory()->create(['name' => 'Tech Cert']);
        Certification::factory()->published()->create(['name' => 'Match', 'category_id' => $category->id]);
        Certification::factory()->published()->create(['name' => 'OtherCat']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['category_id' => $category->id]));

        $response->assertOk();
        $response->assertSee('Match');
        $response->assertDontSee('OtherCat');
    }

    public function test_paginates_20_per_page(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->count(22)->create();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index'));

        $response->assertOk();
        $certs = $response->viewData('certifications');
        $this->assertSame(20, $certs->perPage());
        $this->assertSame(22, $certs->total());
    }
}
