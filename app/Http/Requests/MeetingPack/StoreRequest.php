<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Models\MeetingPack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 面談パックの作成入力。状態・管理者情報はクライアントに決定させない。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MeetingPack::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // validated() に基本情報だけを含め、状態変更・管理者の偽装を保存処理へ渡さない。
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meeting_count' => ['required', 'integer', 'min:1', 'max:100'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'パック名',
            'description' => '説明',
            'meeting_count' => '面談回数',
            'price' => '価格',
            'stripe_price_id' => 'Stripe Price ID',
            'sort_order' => '並び順',
        ];
    }
}
