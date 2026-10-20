<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof AiChatConversation && ($this->user()?->can('sendMessage', $conversation) ?? false);
    }

    public function rules(): array
    {
        return ['content' => ['required', 'string', 'max:2000']];
    }
}
