<?php

declare(strict_types=1);

return [

    'api_key' => env('GEMINI_API_KEY'),

    'api_base' => env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta'),

    'primary_model' => env('GEMINI_PRIMARY_MODEL', 'gemini-3.5-flash-lite'),

    'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.7-flash'),

    'prompt_version' => env('GENERATION_PROMPT_VERSION', 'mcq-v1'),

    'max_questions' => (int) env('GENERATION_MAX_QUESTIONS', 10),

    'max_material_chars' => (int) env('GENERATION_MAX_MATERIAL_CHARS', 80000),

    'http_timeout' => (int) env('GENERATION_HTTP_TIMEOUT', 60),

    'http_connect_timeout' => (int) env('GENERATION_HTTP_CONNECT_TIMEOUT', 10),

    'max_output_tokens' => (int) env('GENERATION_MAX_OUTPUT_TOKENS', 8192),

    'backoff_seconds' => [5, 15],

    'queue_connection' => env('GENERATION_QUEUE_CONNECTION', 'database-generation'),

    'queue' => env('GENERATION_QUEUE', 'question-generation'),

    // 1800 is the minimum safe floor. Operators may configure a higher threshold;
    // runtime recovery raises any lower value to 1800 seconds.
    'stale_after_seconds' => (int) env('GENERATION_STALE_AFTER_SECONDS', 1800),

    'stale_recovery_batch' => (int) env('GENERATION_STALE_RECOVERY_BATCH', 50),

];
