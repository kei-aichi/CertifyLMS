<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $targetType = $this->input('target_type');

        return [
            'target_type' => ['required', Rule::enum(AnnouncementTargetType::class)],
            'target_certification_id' => [
                Rule::requiredIf($targetType === AnnouncementTargetType::Certification->value),
                Rule::prohibitedIf($targetType !== AnnouncementTargetType::Certification->value),
                'nullable',
                'ulid',
                'exists:certifications,id',
            ],
            'target_user_id' => [
                Rule::requiredIf($targetType === AnnouncementTargetType::User->value),
                Rule::prohibitedIf($targetType !== AnnouncementTargetType::User->value),
                'nullable',
                'ulid',
                Rule::exists('users', 'id')->where(function ($query): void {
                    $query
                        ->where('role', UserRole::Student->value)
                        ->where('status', UserStatus::InProgress->value)
                        ->whereNull('deleted_at');
                }),
            ],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'submission_key' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'target_type' => '配信対象',
            'target_certification_id' => '対象資格',
            'target_user_id' => '対象受講生',
            'title' => 'タイトル',
            'body' => '本文',
            'submission_key' => '送信キー',
        ];
    }
}
