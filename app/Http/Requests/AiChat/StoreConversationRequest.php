<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AiChatConversation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', Rule::in(['widget', 'full-screen'])],
            'message' => ['nullable', 'string', 'max:2000'],
            'section_id' => ['nullable', 'ulid', 'exists:sections,id'],
            'enrollment_id' => ['nullable', 'ulid', 'exists:enrollments,id'],
            'force_new' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            $enrollmentId = $this->input('enrollment_id');
            $sectionId = $this->input('section_id');

            if ($user === null) {
                return;
            }

            $enrollment = $enrollmentId === null
                ? null
                : $user->enrollments()
                    ->whereKey($enrollmentId)
                    ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
                    ->first();

            if ($enrollmentId !== null && $enrollment === null) {
                $validator->errors()->add('enrollment_id', '選択した受講登録を利用できません。');
            }

            if ($sectionId !== null) {
                $section = Section::query()->find($sectionId);

                if ($section === null || ! Gate::forUser($user)->allows('learning.section.view', $section)) {
                    $validator->errors()->add('section_id', '選択した教材Sectionを利用できません。');
                } elseif ($enrollment !== null && $section->chapter?->part?->certification_id !== $enrollment->certification_id) {
                    $validator->errors()->add('section_id', 'Sectionと受講登録の資格が一致しません。');
                }
            }
        });
    }
}
