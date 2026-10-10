<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Rules\NotWhitespaceOnly;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('note')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:2000', new NotWhitespaceOnly]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['body' => 'メモ本文'];
    }
}
