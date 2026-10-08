<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: UserRole, 1: string}> */
    public static function roles(): array
    {
        return [
            'student' => [UserRole::Student, 'student'],
            'coach' => [UserRole::Coach, 'coach'],
            'admin' => [UserRole::Admin, 'admin'],
        ];
    }

    /** @dataProvider roles */
    public function test_all_roles_can_view_own_profile(UserRole $role, string $roleLabel): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get(route('settings.profile.edit'));

        $response->assertOk()
            ->assertViewIs('settings.profile')
            ->assertViewHas('user', fn (User $viewUser): bool => $viewUser->is($user))
            ->assertSeeText($user->name)
            ->assertSee('value="'.$user->email.'"', false)
            ->assertSeeText($user->role->label())
            ->assertSeeText($user->status->label());

        if ($role === UserRole::Coach) {
            $response->assertSee('name="meeting_url"', false);
        } else {
            $response->assertDontSee('name="meeting_url"', false);
        }
    }

    public function test_graduated_student_can_view_profile(): void
    {
        $user = User::factory()->graduated()->create();

        $this->actingAs($user)->get(route('settings.profile.edit'))->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.profile.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_profile_uses_authenticated_user_only(): void
    {
        $user = User::factory()->student()->create();
        $other = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get(route('settings.profile.edit').'?user='.$other->id)
            ->assertOk()
            ->assertSee('value="'.$user->email.'"', false)
            ->assertDontSee('value="'.$other->email.'"', false);
    }

    public function test_profile_and_password_tabs_render(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)->get(route('settings.profile.edit', ['tab' => 'profile']))
            ->assertOk()
            ->assertSeeText('プロフィール情報')
            ->assertSeeText('アイコン画像');

        $this->actingAs($user)->get(route('settings.profile.edit', ['tab' => 'password']))
            ->assertOk()
            ->assertSeeText('パスワード変更')
            ->assertSee('name="current_password"', false);
    }
}
