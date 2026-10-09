<?php

declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class NotWhitespaceOnly implements Rule
{
    public function passes($attribute, $value): bool
    {
        return is_string($value)
            && preg_match('/[^\s\p{Z}]/u', $value) === 1;
    }

    public function message(): string
    {
        return ':attributeには空白以外の文字を入力してください。';
    }
}
