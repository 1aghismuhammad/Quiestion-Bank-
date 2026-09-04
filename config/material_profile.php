<?php

declare(strict_types=1);

return [

    'chunk_core_max_chars' => 12_000,

    'chunk_overlap_chars' => 400,

    'max_chunks' => 20,

    'max_canonical_chars' => 240_000,

    'processing_lease_seconds' => 120,

    'queued_abandonment_seconds' => 900,

    'job_timeout_seconds' => 270,

    'provider_http_timeout_seconds' => 60,

    'provider_connect_timeout_seconds' => 10,

    'stale_recovery_batch_size' => 50,

    'queue_connection' => env('MATERIAL_PROFILE_QUEUE_CONNECTION', 'database-generation'),

    'queue' => env('MATERIAL_PROFILE_QUEUE', 'material-intelligence'),

    'extractor_implementation' => 'pdfparser:smalot:2+MaterialExtractorRouter',

    'api_key' => env('GEMINI_API_KEY'),

    'api_base' => env('MATERIAL_PROFILE_API_BASE', 'https://generativelanguage.googleapis.com/v1beta'),

    'primary_model' => env('MATERIAL_PROFILE_PRIMARY_MODEL', 'gemini-3.5-flash-lite'),

    'map_prompt_version' => env('MATERIAL_PROFILE_MAP_PROMPT_VERSION', 'profile-map-v1'),

    'reduce_prompt_version' => env('MATERIAL_PROFILE_REDUCE_PROMPT_VERSION', 'profile-reduce-v1'),

    'max_output_tokens' => (int) env('MATERIAL_PROFILE_MAX_OUTPUT_TOKENS', 8192),

    'max_provider_attempts' => 3,

    'backoff_seconds' => [5, 15],

    'new_analysis_per_hour' => 3,

    'throttle_window_seconds' => 3_600,

    // max_map_candidates * max_chunks must stay <= max_reduce_summaries so every
    // persisted extracted Element can reach reduce without silent truncation.
    'max_map_candidates' => 10,

    'max_suggested_elements' => 40,

    'max_reduce_summaries' => 200,

    'max_element_text_chars' => 300,

    'max_evidence_chars' => 500,

    'status_poll_interval_ms' => 5_000,

];
