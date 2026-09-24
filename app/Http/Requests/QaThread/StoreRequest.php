<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\CertificationStatus;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 質問投稿。投稿者・初期状態はサーバー側で決め、入力として受け取らない。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 資格IDの不備は認可エラーではなく、rules()で入力エラーとして扱う。
        return $this->user()?->can('create', QaThread::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // 未受講の資格にも投稿できるため、Enrollmentの条件は付けない。
            'certification_id' => ['bail', 'required', 'ulid', Rule::exists('certifications', 'id')->where('status', CertificationStatus::Published->value)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'certification_id' => '資格',
            'title' => 'タイトル',
            'body' => '本文',
        ];
    }
}
