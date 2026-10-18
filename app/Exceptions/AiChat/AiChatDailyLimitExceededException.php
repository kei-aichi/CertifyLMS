<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class AiChatDailyLimitExceededException extends TooManyRequestsHttpException
{
    public function __construct()
    {
        parent::__construct(null, '本日のAIチャット利用上限に達しました。');
    }
}
