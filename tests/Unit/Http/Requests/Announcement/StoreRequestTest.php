<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserStatus;
use App\Http\Requests\Announcement\StoreRequest;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoreRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_payload_is_valid_without_target_ids(): void
    {
        $this->assertValid($this->payload(['target_type' => AnnouncementTargetType::AllStudents->value]));
    }

    public function test_certification_payload_is_valid_with_existing_certification(): void
    {
        $certification = Certification::factory()->draft()->create();

        $this->assertValid($this->payload([
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
        ]));
    }

    public function test_user_payload_is_valid_for_active_student(): void
    {
        $student = User::factory()->student()->create();

        $this->assertValid($this->payload([
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $student->id,
        ]));
    }

    public function test_existing_submission_key_is_not_rejected_by_request_validation(): void
    {
        $this->assertValid($this->payload(['submission_key' => 'already-used-key']));
    }

    public function test_non_admin_is_not_authorized(): void
    {
        $request = StoreRequest::create('/', 'POST');
        $request->setUserResolver(fn () => User::factory()->student()->make());

        $this->assertFalse($request->authorize());
    }

    public function test_title_and_body_boundaries_are_validated(): void
    {
        $this->assertValid($this->payload([
            'title' => str_repeat('あ', 200),
            'body' => str_repeat('い', 5000),
        ]));
        $this->assertInvalid($this->payload(['title' => str_repeat('あ', 201)]), 'title');
        $this->assertInvalid($this->payload(['body' => str_repeat('い', 5001)]), 'body');
    }

    public function test_target_type_and_target_ids_must_match(): void
    {
        $certification = Certification::factory()->create();
        $student = User::factory()->student()->create();

        $this->assertInvalid($this->payload([
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => $certification->id,
        ]), 'target_certification_id');
        $this->assertInvalid($this->payload([
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_user_id' => $student->id,
        ]), 'target_user_id');
        $this->assertInvalid($this->payload([
            'target_type' => AnnouncementTargetType::Certification->value,
        ]), 'target_certification_id');
        $this->assertInvalid($this->payload([
            'target_type' => AnnouncementTargetType::User->value,
        ]), 'target_user_id');
    }

    public function test_invalid_ids_and_multiple_user_ids_are_rejected(): void
    {
        $this->assertInvalid($this->payload([
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => (string) Str::ulid(),
        ]), 'target_certification_id');
        $this->assertInvalid($this->payload([
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => [(string) Str::ulid()],
        ]), 'target_user_id');
        $this->assertInvalid($this->payload([
            'target_type' => 'invalid',
        ]), 'target_type');
    }

    public function test_user_target_requires_active_non_deleted_student(): void
    {
        $graduated = User::factory()->student()->graduated()->create();
        $deleted = User::factory()->student()->create();
        $deleted->delete();
        $coach = User::factory()->coach()->create(['status' => UserStatus::InProgress->value]);

        foreach ([$graduated, $deleted, $coach] as $user) {
            $this->assertInvalid($this->payload([
                'target_type' => AnnouncementTargetType::User->value,
                'target_user_id' => $user->id,
            ]), 'target_user_id');
        }
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'title' => 'お知らせタイトル',
            'body' => 'お知らせ本文',
            'submission_key' => 'submission-key',
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function assertValid(array $payload): void
    {
        $request = StoreRequest::create('/', 'POST', $payload);

        $this->assertTrue(Validator::make($payload, $request->rules())->passes());
    }

    /** @param array<string, mixed> $payload */
    private function assertInvalid(array $payload, string $field): void
    {
        $request = StoreRequest::create('/', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey($field, $validator->errors()->toArray());
    }
}
