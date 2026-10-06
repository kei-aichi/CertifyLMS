<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * プランの作成入力。状態・管理者情報はクライアントに決定させない。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Plan::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // 状態・管理者情報は検証済みデータに含めず、作成処理で決定する。
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'default_meeting_quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'プラン名',
            'description' => '説明',
            'duration_days' => '受講期間(日)',
            'default_meeting_quota' => '初期付与面談回数',
            'sort_order' => '並び順',
        ];
    }
}
