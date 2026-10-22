<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tab' => ['nullable', 'string', Rule::in(['all', 'unread'])],
            'page' => ['prohibited'],
        ];
    }
}
