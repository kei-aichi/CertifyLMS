<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class AiChatPendingException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('この会話は現在処理中です。しばらく待ってから再送してください。');
    }
}
