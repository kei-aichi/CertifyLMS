<?php

declare(strict_types=1);

namespace Tests\Feature\Http\CertificationCategory;

use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_soft_delete_unused_category(): void
    {
        $admin = User::factory()->admin()->create();
        $category = CertificationCategory::factory()->create();

        $response = $this->actingAs($admin)->delete(route('admin.certification-categories.destroy', $category));

        $response
            ->assertStatus(302)
            ->assertRedirect(route('admin.certification-categories.index'))
            ->assertSessionHas('success', '分類を削除しました。');
        $this->assertDatabaseMissing('certification_categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_category_in_use(): void
    {
        $admin = User::factory()->admin()->create();
        $category = CertificationCategory::factory()->create();
        Certification::factory()->draft()->create(['category_id' => $category->id]);

        $response = $this->actingAs($admin)->deleteJson(route('admin.certification-categories.destroy', $category));

        $response->assertStatus(409);
        $response->assertSessionMissing('success');
        $this->assertDatabaseHas('certification_categories', ['id' => $category->id]);
    }

    public function test_coach_cannot_delete_category(): void
    {
        $coach = User::factory()->coach()->create();
        $category = CertificationCategory::factory()->create();

        $response = $this->actingAs($coach)->delete(route('admin.certification-categories.destroy', $category));

        $response->assertForbidden();
        $this->assertDatabaseHas('certification_categories', ['id' => $category->id]);
    }

    public function test_student_cannot_delete_category(): void
    {
        $student = User::factory()->student()->create();
        $category = CertificationCategory::factory()->create();

        $response = $this->actingAs($student)->delete(route('admin.certification-categories.destroy', $category));

        $response->assertForbidden();
        $this->assertDatabaseHas('certification_categories', ['id' => $category->id]);
    }

    public function test_guest_cannot_delete_category(): void
    {
        $category = CertificationCategory::factory()->create();

        $response = $this->delete(route('admin.certification-categories.destroy', $category));

        $response
            ->assertStatus(302)
            ->assertRedirect('/login');
        $this->assertDatabaseHas('certification_categories', ['id' => $category->id]);
    }
}
