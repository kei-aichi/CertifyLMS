<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AI_CHAT_ENABLED', false),
    'daily_limit' => (int) env('AI_CHAT_DAILY_LIMIT', 20),
    'auto_title_enabled' => (bool) env('AI_CHAT_AUTO_TITLE_ENABLED', true),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'max_retries' => (int) env('GEMINI_MAX_RETRIES', 2),
    ],
];
