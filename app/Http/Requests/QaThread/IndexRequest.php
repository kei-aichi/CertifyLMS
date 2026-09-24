<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 一覧入口を認可し、提供済み検索フォームの入力だけを検証する。 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', QaThread::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'certification_id' => ['nullable', 'ulid', 'exists:certifications,id'],
            // Bladeのunresolvedは画面上の値。保存値openへの変換は取得処理で行う。
            'status' => ['nullable', Rule::in(['open', 'unresolved', 'resolved'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['keyword' => 'キーワード', 'certification_id' => '資格', 'status' => '解決状態', 'page' => 'ページ'];
    }
}
