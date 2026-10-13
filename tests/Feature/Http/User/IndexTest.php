<?php

declare(strict_types=1);

namespace Tests\Feature\Http\User;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\UserWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_user_list(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertViewIs('user.management.index');
        $response->assertViewHas('users');
    }

    public function test_coach_and_student_cannot_access_admin_users_index(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $this->actingAs($coach)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($student)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_keyword_search_filters_by_name_and_email(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->create(['name' => '山田太郎', 'email' => 'yamada@example.test']);
        User::factory()->student()->create(['name' => '佐藤花子', 'email' => 'sato@example.test']);
        User::factory()->student()->create(['name' => 'Smith John', 'email' => 'smith@example.test']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['keyword' => '山田']));
        $response->assertOk();
        $response->assertSee('yamada@example.test');
        $response->assertDontSee('sato@example.test');

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['keyword' => 'sato@']));
        $response->assertOk();
        $response->assertSee('sato@example.test');
        $response->assertDontSee('yamada@example.test');
    }

    public function test_role_filter_returns_only_matching_role(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
        User::factory()->coach()->create(['email' => 'coach1@example.test']);
        User::factory()->student()->create(['email' => 'student1@example.test']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'coach']));

        $response->assertOk();
        $response->assertSee('coach1@example.test');
        $response->assertDontSee('student1@example.test');
        $response->assertDontSee('admin@example.test');
    }

    public function test_status_filter_returns_only_matching_status(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'active1@example.test', 'status' => UserStatus::InProgress->value]);
        User::factory()->invited()->create(['email' => 'invited1@example.test']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'invited']));

        $response->assertOk();
        $response->assertSee('invited1@example.test');
        $response->assertDontSee('active1@example.test');
    }

    public function test_status_filter_excludes_withdrawn_by_default(): void
    {
        $admin = User::factory()->admin()->create();
        $active = User::factory()->create(['email' => 'active@example.test']);
        $gone = User::factory()->create(['email' => 'gone@example.test']);
        app(UserWithdrawalService::class)->withdraw($gone);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee('active@example.test');
        $response->assertDontSee('gone@example.test');
        // UserWithdrawalService 経由で email が {ulid}@deleted.invalid にリネームされる
        $response->assertDontSee($gone->fresh()->email);
    }

    public function test_status_filter_includes_withdrawn_when_explicitly_selected(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'active@example.test']);
        $gone = User::factory()->create(['email' => 'gone@example.test']);
        app(UserWithdrawalService::class)->withdraw($gone);
        $renamedEmail = $gone->fresh()->email;

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'withdrawn']));

        $response->assertOk();
        $response->assertSee($renamedEmail);
        $response->assertDontSee('active@example.test');
    }

    public function test_default_list_excludes_withdrawn_users_and_soft_deleted_inconsistent_users(): void
    {
        $admin = User::factory()->admin()->create();
        $visible = User::factory()->student()->create([
            'email' => 'visible@example.test',
            'status' => UserStatus::InProgress->value,
            'deleted_at' => null,
        ]);
        $withdrawn = User::factory()->student()->create([
            'email' => 'withdrawn@example.test',
            'status' => UserStatus::Withdrawn->value,
            'deleted_at' => null,
        ]);
        $softDeletedWithdrawn = $this->createSoftDeletedUser([
            'email' => 'soft-deleted-withdrawn@example.test',
            'status' => UserStatus::Withdrawn->value,
        ]);
        $softDeletedActive = $this->createSoftDeletedUser([
            'email' => 'soft-deleted-active@example.test',
            'status' => UserStatus::InProgress->value,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee($visible->email);
        $response->assertDontSee($withdrawn->email);
        $response->assertDontSee($softDeletedWithdrawn->email);
        $response->assertDontSee($softDeletedActive->email);
        $this->assertSame(2, $response->viewData('users')->total());
    }

    public function test_withdrawn_filter_includes_soft_deleted_and_not_soft_deleted_withdrawn_users(): void
    {
        $admin = User::factory()->admin()->create();
        $withdrawn = User::factory()->student()->create([
            'email' => 'withdrawn@example.test',
            'status' => UserStatus::Withdrawn->value,
            'deleted_at' => null,
        ]);
        $softDeletedWithdrawn = $this->createSoftDeletedUser([
            'email' => 'soft-deleted-withdrawn@example.test',
            'status' => UserStatus::Withdrawn->value,
        ]);
        $active = User::factory()->student()->create([
            'email' => 'active@example.test',
            'status' => UserStatus::InProgress->value,
            'deleted_at' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'withdrawn']));

        $response->assertOk();
        $response->assertSee($withdrawn->email);
        $response->assertSee($softDeletedWithdrawn->email);
        $response->assertDontSee($active->email);
        $this->assertSame(2, $response->viewData('users')->total());
    }

    public function test_status_filters_include_only_matching_not_soft_deleted_users(): void
    {
        $admin = User::factory()->admin()->create([
            'status' => UserStatus::Withdrawn->value,
            'deleted_at' => null,
        ]);

        foreach (UserStatus::cases() as $status) {
            if ($status === UserStatus::Withdrawn) {
                continue;
            }

            $visible = User::factory()->student()->create([
                'email' => $status->value.'-visible@example.test',
                'status' => $status->value,
                'deleted_at' => null,
            ]);
            $softDeleted = $this->createSoftDeletedUser([
                'email' => $status->value.'-soft-deleted@example.test',
                'status' => $status->value,
            ]);

            $response = $this->actingAs($admin)->get(route('admin.users.index', ['status' => $status->value]));

            $response->assertOk();
            $response->assertSee($visible->email);
            $response->assertDontSee($softDeleted->email);
            $this->assertSame(1, $response->viewData('users')->total());
        }
    }

    public function test_status_filter_is_applied_consistently_to_all_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $users = collect([
            User::factory()->admin()->create(['email' => 'admin-withdrawn@example.test', 'deleted_at' => null]),
            User::factory()->coach()->create(['email' => 'coach-withdrawn@example.test', 'deleted_at' => null]),
            User::factory()->student()->create(['email' => 'student-withdrawn@example.test', 'deleted_at' => null]),
        ]);
        $users->each(fn (User $user) => $user->forceFill(['status' => UserStatus::Withdrawn])->save());

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $users->each(fn (User $user) => $response->assertDontSee($user->email));
        $this->assertSame(1, $response->viewData('users')->total());

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'withdrawn']));

        $response->assertOk();
        $users->each(fn (User $user) => $response->assertSee($user->email));
        $this->assertSame(3, $response->viewData('users')->total());
    }

    public function test_keyword_and_role_filters_combine_with_withdrawn_status_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $matching = User::factory()->coach()->create([
            'name' => '対象コーチ',
            'email' => 'target-coach@example.test',
            'status' => UserStatus::Withdrawn->value,
            'deleted_at' => null,
        ]);
        $otherRole = User::factory()->student()->create([
            'name' => '対象受講生',
            'email' => 'target-student@example.test',
            'status' => UserStatus::Withdrawn->value,
            'deleted_at' => null,
        ]);
        $otherKeyword = User::factory()->coach()->create([
            'name' => '別コーチ',
            'email' => 'other-coach@example.test',
            'status' => UserStatus::Withdrawn->value,
            'deleted_at' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index', [
            'keyword' => '対象',
            'role' => 'coach',
            'status' => 'withdrawn',
        ]));

        $response->assertOk();
        $response->assertSee($matching->email);
        $response->assertDontSee($otherRole->email);
        $response->assertDontSee($otherKeyword->email);
        $this->assertSame(1, $response->viewData('users')->total());
    }

    public function test_paginates_20_per_page(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->count(25)->create();

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $users = $response->viewData('users');
        $this->assertSame(20, $users->perPage());
        $this->assertSame(26, $users->total()); // 25 students + 1 admin
    }

    public function test_orders_by_status_priority_then_created_at_desc(): void
    {
        $admin = User::factory()->admin()->create();

        $oldInvited = User::factory()->invited()->create([
            'email' => 'old-invited@example.test',
            'created_at' => now()->subDays(5),
        ]);
        $newActive = User::factory()->create([
            'email' => 'new-active@example.test',
            'created_at' => now()->subHour(),
        ]);
        $oldActive = User::factory()->create([
            'email' => 'old-active@example.test',
            'created_at' => now()->subDays(10),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $users = $response->viewData('users');
        $emails = $users->getCollection()->pluck('email')->all();

        $newActivePos = array_search('new-active@example.test', $emails);
        $oldActivePos = array_search('old-active@example.test', $emails);
        $oldInvitedPos = array_search('old-invited@example.test', $emails);

        // Active comes before Invited (status priority)
        $this->assertLessThan($oldInvitedPos, $newActivePos);
        $this->assertLessThan($oldInvitedPos, $oldActivePos);
        // Within Active: newer created_at comes first
        $this->assertLessThan($oldActivePos, $newActivePos);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createSoftDeletedUser(array $attributes): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['deleted_at' => now()])->save();

        return $user->fresh();
    }
}
