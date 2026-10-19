<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AI_CHAT_ENABLED', false),
    'daily_limit' => (int) env('AI_CHAT_DAILY_LIMIT', 20),
    'auto_title_enabled' => (bool) env('AI_CHAT_AUTO_TITLE_ENABLED', true),
    'pending_timeout_seconds' => (int) env('AI_CHAT_PENDING_TIMEOUT_SECONDS', 120),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'max_retries' => (int) env('GEMINI_MAX_RETRIES', 2),
        'title_timeout' => (int) env('GEMINI_TITLE_TIMEOUT', 10),
        'title_max_retries' => (int) env('GEMINI_TITLE_MAX_RETRIES', 0),
    ],
];
