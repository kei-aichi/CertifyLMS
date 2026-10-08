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

    public function test_user_can_update_name_and_bio_without_changing_managed_fields(): void
    {
        $user = User::factory()->coach()->create([
            'name' => 'Before',
            'bio' => 'Old bio',
            'meeting_url' => 'https://example.test/meeting',
        ]);
        $original = $user->only(['email', 'role', 'status', 'password', 'avatar_url', 'meeting_url']);

        $this->actingAs($user)
            ->patch(route('settings.profile.update'), [
                'name' => 'After',
                'bio' => 'New bio',
                'email' => 'attacker@example.test',
                'role' => UserRole::Admin->value,
                'status' => 'graduated',
                'password' => 'attacker-password',
                'avatar_url' => 'https://attacker.test/avatar.png',
                'meeting_url' => 'https://attacker.test/meeting',
            ])
            ->assertRedirect(route('settings.profile.edit'))
            ->assertSessionHas('success', 'プロフィールを更新しました。');

        $user->refresh();
        $this->assertSame('After', $user->name);
        $this->assertSame('New bio', $user->bio);
        $this->assertSame($original, $user->only(array_keys($original)));
    }

    public function test_all_roles_and_graduated_student_can_update_profile(): void
    {
        $users = [
            User::factory()->student()->create(),
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
            User::factory()->graduated()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->patch(route('settings.profile.update'), [
                    'name' => str_repeat('名', 50),
                    'bio' => str_repeat('紹', 1000),
                ])
                ->assertRedirect(route('settings.profile.edit'));

            $user->refresh();
            $this->assertSame(str_repeat('名', 50), $user->name);
            $this->assertSame(str_repeat('紹', 1000), $user->bio);
        }
    }

    public function test_blank_bio_is_saved_as_null(): void
    {
        $user = User::factory()->create(['bio' => 'Existing']);

        $this->actingAs($user)
            ->patch(route('settings.profile.update'), ['name' => $user->name, 'bio' => ''])
            ->assertRedirect(route('settings.profile.edit'));

        $this->assertNull($user->refresh()->bio);
    }

    /** @dataProvider invalidNamePayloads */
    public function test_missing_or_blank_name_is_rejected_and_keeps_database_value(array $payload): void
    {
        $user = User::factory()->create(['name' => 'Original']);

        $this->actingAs($user)
            ->from(route('settings.profile.edit'))
            ->patch(route('settings.profile.update'), $payload)
            ->assertRedirect(route('settings.profile.edit'))
            ->assertSessionHasErrors('name');

        $this->assertSame('Original', $user->refresh()->name);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function invalidNamePayloads(): array
    {
        return [
            'missing' => [['bio' => 'Bio']],
            'empty' => [['name' => '', 'bio' => 'Bio']],
            'whitespace only' => [['name' => '   ', 'bio' => 'Bio']],
        ];
    }

    /** @dataProvider invalidProfileInputs */
    public function test_profile_validation_rejects_invalid_input(string $field, mixed $value): void
    {
        $user = User::factory()->create(['name' => 'Original', 'bio' => 'Original bio']);

        $this->actingAs($user)
            ->from(route('settings.profile.edit'))
            ->patch(route('settings.profile.update'), ['name' => $user->name, 'bio' => $user->bio, $field => $value])
            ->assertRedirect(route('settings.profile.edit'))
            ->assertSessionHasErrors($field);

        $this->assertSame('Original', $user->refresh()->name);
        $this->assertSame('Original bio', $user->bio);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function invalidProfileInputs(): array
    {
        return [
            'missing name' => ['name', ''],
            'name over limit' => ['name', str_repeat('a', 51)],
            'bio over limit' => ['bio', str_repeat('a', 1001)],
        ];
    }

    public function test_guest_cannot_update_profile(): void
    {
        $this->patch(route('settings.profile.update'), ['name' => 'New name'])
            ->assertRedirect(route('login'));
    }

    public function test_other_user_is_not_updated(): void
    {
        $user = User::factory()->create(['name' => 'Owner']);
        $other = User::factory()->create(['name' => 'Other']);

        $this->actingAs($user)->patch(route('settings.profile.update'), ['name' => 'Changed']);

        $this->assertSame('Changed', $user->refresh()->name);
        $this->assertSame('Other', $other->refresh()->name);
    }
}
