<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Enums\UserRole;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Settings\DestroyAvatarAction;
use App\UseCases\Settings\StoreAvatarAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
        $user = User::factory()->student()->create([
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

    public function test_coach_can_update_meeting_url_and_clear_it(): void
    {
        $coach = User::factory()->coach()->create(['meeting_url' => 'https://old.example/room']);

        $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => 'Coach',
            'bio' => 'Bio',
            'meeting_url' => 'https://new.example/room',
        ])->assertRedirect(route('settings.profile.edit'));
        $this->assertSame('https://new.example/room', $coach->refresh()->meeting_url);

        $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => 'Coach',
            'bio' => 'Bio',
            'meeting_url' => '',
        ])->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($coach->refresh()->meeting_url);
    }

    public function test_student_and_admin_cannot_spoof_meeting_url(): void
    {
        foreach ([User::factory()->student(), User::factory()->admin()] as $factory) {
            $user = $factory->create(['meeting_url' => 'https://original.example/room']);

            $this->actingAs($user)->patch(route('settings.profile.update'), [
                'name' => $user->name,
                'bio' => $user->bio,
                'meeting_url' => 'https://attacker.example/room',
            ])->assertRedirect(route('settings.profile.edit'));

            $this->assertSame('https://original.example/room', $user->refresh()->meeting_url);
        }
    }

    /** @dataProvider invalidMeetingUrls */
    public function test_coach_meeting_url_validation_preserves_existing_value(string $url): void
    {
        $coach = User::factory()->coach()->create(['meeting_url' => 'https://original.example/room']);

        $this->actingAs($coach)->from(route('settings.profile.edit'))->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'bio' => $coach->bio,
            'meeting_url' => $url,
        ])->assertRedirect(route('settings.profile.edit'))->assertSessionHasErrors('meeting_url');

        $this->assertSame('https://original.example/room', $coach->refresh()->meeting_url);
    }

    public function test_coach_can_save_a_500_character_meeting_url(): void
    {
        $coach = User::factory()->coach()->create();
        $url = 'https://example.com/'.str_repeat('a', 480);
        $this->assertSame(500, strlen($url));

        $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'bio' => $coach->bio,
            'meeting_url' => $url,
        ])->assertRedirect(route('settings.profile.edit'));

        $this->assertSame($url, $coach->refresh()->meeting_url);
    }

    public function test_coach_url_update_does_not_change_existing_meeting_snapshot(): void
    {
        $coach = User::factory()->coach()->create(['meeting_url' => 'https://old.example/room']);
        $meeting = Meeting::factory()->forCoach($coach)->create([
            'meeting_url_snapshot' => 'https://old.example/room',
        ]);

        $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'bio' => $coach->bio,
            'meeting_url' => 'https://new.example/room',
        ])->assertRedirect(route('settings.profile.edit'));

        $this->assertSame('https://old.example/room', $meeting->refresh()->meeting_url_snapshot);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidMeetingUrls(): array
    {
        return [
            'invalid url' => ['not-a-url'],
            'over 500 chars' => ['https://example.com/'.str_repeat('a', 490)],
        ];
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

    public function test_all_roles_and_graduated_student_can_change_password(): void
    {
        $users = [
            User::factory()->student()->create(),
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
            User::factory()->graduated()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->put(route('settings.password.update'), [
                    'current_password' => 'password',
                    'password' => 'new-password-123',
                    'password_confirmation' => 'new-password-123',
                ])
                ->assertRedirect(route('settings.profile.edit', ['tab' => 'password']))
                ->assertSessionHas('success', 'パスワードを変更しました。');

            $this->assertTrue(Hash::check('new-password-123', $user->refresh()->password));
        }
    }

    public function test_eight_character_password_replaces_old_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'Abcdef12',
                'password_confirmation' => 'Abcdef12',
            ])
            ->assertRedirect(route('settings.profile.edit', ['tab' => 'password']))
            ->assertSessionHas('success', 'パスワードを変更しました。');

        $hash = $user->refresh()->password;
        $this->assertFalse(Hash::check('password', $hash));
        $this->assertTrue(Hash::check('Abcdef12', $hash));
    }

    /** @dataProvider invalidPasswordInputs */
    public function test_invalid_password_input_keeps_database_password_and_uses_password_error_bag(array $input): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;
        $passwordUrl = route('settings.profile.edit', ['tab' => 'password']);

        $this->actingAs($user)
            ->from($passwordUrl)
            ->put(route('settings.password.update'), $input)
            ->assertRedirect($passwordUrl)
            ->assertSessionHas('errors');

        $errors = session('errors');
        $this->assertTrue($errors->getBag('updatePassword')->any());
        $this->assertSame($originalHash, $user->refresh()->password);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function invalidPasswordInputs(): array
    {
        return [
            'current password missing' => [[
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]],
            'current password mismatch' => [[
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]],
            'new password missing' => [[
                'current_password' => 'password',
                'password_confirmation' => '',
            ]],
            'new password too short' => [[
                'current_password' => 'password',
                'password' => '1234567',
                'password_confirmation' => '1234567',
            ]],
            'confirmation missing' => [[
                'current_password' => 'password',
                'password' => 'new-password-123',
            ]],
            'confirmation mismatch' => [[
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'different-password',
            ]],
        ];
    }

    public function test_other_user_is_not_updated(): void
    {
        $user = User::factory()->create(['name' => 'Owner']);
        $other = User::factory()->create(['name' => 'Other']);

        $this->actingAs($user)->patch(route('settings.profile.update'), ['name' => 'Changed']);

        $this->assertSame('Changed', $user->refresh()->name);
        $this->assertSame('Other', $other->refresh()->name);
    }

    public function test_fortify_profile_information_route_is_disabled(): void
    {
        $this->assertFalse(Route::has('user-profile-information.update'));

        $user = User::factory()->create(['email' => 'original@example.test']);
        $response = $this->actingAs($user)->put('/user/profile-information', [
            'name' => 'Changed through old route',
            'email' => 'changed@example.test',
        ]);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame('original@example.test', $user->refresh()->email);
    }

    public function test_all_roles_and_graduated_student_can_upload_avatar(): void
    {
        Storage::fake('public');
        $users = [
            User::factory()->student()->create(), User::factory()->coach()->create(),
            User::factory()->admin()->create(), User::factory()->graduated()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('avatar.png'),
            ])->assertRedirect(route('settings.profile.edit'));
            $url = $user->refresh()->avatar_url;
            $path = ltrim(str_replace('/storage/', '', (string) parse_url($url, PHP_URL_PATH)), '/');
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_avatar_validation_rejects_invalid_type_and_oversized_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('settings.profile.edit'))->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->create('avatar.svg', 10, 'image/svg+xml'),
        ])->assertRedirect(route('settings.profile.edit'))->assertSessionHasErrors('avatar');

        $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->create('avatar.png', 2048, 'image/png'),
        ])->assertRedirect(route('settings.profile.edit'));
        $savedUrl = $user->refresh()->avatar_url;
        $this->assertNotNull($savedUrl);

        $this->actingAs($user)->from(route('settings.profile.edit'))->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->create('avatar.png', 2049, 'image/png'),
        ])->assertRedirect(route('settings.profile.edit'))->assertSessionHasErrors('avatar');
        $this->assertSame($savedUrl, $user->refresh()->avatar_url);
    }

    public function test_avatar_replacement_deletes_old_file_and_delete_clears_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.avatar.store'), ['avatar' => UploadedFile::fake()->image('old.jpg')]);
        $oldPath = ltrim(str_replace('/storage/', '', (string) parse_url($user->refresh()->avatar_url, PHP_URL_PATH)), '/');
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($user)->post(route('settings.avatar.store'), ['avatar' => UploadedFile::fake()->image('new.webp')]);
        $newPath = ltrim(str_replace('/storage/', '', (string) parse_url($user->refresh()->avatar_url, PHP_URL_PATH)), '/');
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);

        $this->actingAs($user)->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($user->refresh()->avatar_url);
        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_avatar_delete_without_existing_file_is_safe_and_guest_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user)->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($user->refresh()->avatar_url);
        Auth::logout();
        $this->delete(route('settings.avatar.destroy'))->assertRedirect(route('login'));
    }

    public function test_upload_db_failure_removes_new_file_and_preserves_existing_avatar(): void
    {
        Storage::fake('public');
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = '01h00000000000000000000000';
        $oldPath = "avatars/{$user->id}/old.png";
        $user->avatar_url = Storage::disk('public')->url($oldPath);
        Storage::disk('public')->put($oldPath, 'old');
        $user->shouldReceive('update')->once()->andThrow(new \RuntimeException('db failure'));

        try {
            (new StoreAvatarAction)($user, UploadedFile::fake()->image('new.png'));
            $this->fail('Expected the database failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('db failure', $exception->getMessage());
        }

        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles());
    }

    public function test_delete_db_failure_preserves_existing_avatar_and_file(): void
    {
        Storage::fake('public');
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = '01h00000000000000000000000';
        $path = "avatars/{$user->id}/avatar.png";
        $user->avatar_url = Storage::disk('public')->url($path);
        Storage::disk('public')->put($path, 'avatar');
        $user->shouldReceive('update')->once()->andThrow(new \RuntimeException('db failure'));

        try {
            (new DestroyAvatarAction)($user);
            $this->fail('Expected the database failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('db failure', $exception->getMessage());
        }

        Storage::disk('public')->assertExists($path);
        $this->assertSame(Storage::disk('public')->url($path), $user->avatar_url);
    }

    public function test_delete_does_not_remove_other_users_or_unmanaged_avatar_files(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $paths = [
            "avatars/{$other->id}/avatar.png",
            'misc/avatar.png',
        ];

        foreach ($paths as $path) {
            Storage::disk('public')->put($path, 'avatar');
            $user->update(['avatar_url' => Storage::disk('public')->url($path)]);
            $this->actingAs($user)->delete(route('settings.avatar.destroy'))
                ->assertRedirect(route('settings.profile.edit'));
            Storage::disk('public')->assertExists($path);
        }

        $user->update(['avatar_url' => 'https://external.example/avatar.png']);
        $this->actingAs($user)->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($user->refresh()->avatar_url);
    }
}
