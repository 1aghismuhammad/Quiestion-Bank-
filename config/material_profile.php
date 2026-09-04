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

];
