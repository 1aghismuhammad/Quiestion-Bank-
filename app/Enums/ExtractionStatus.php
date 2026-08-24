<?php

declare(strict_types=1);

namespace App\Enums;

enum ExtractionStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case NOT_REQUIRED = 'not_required';
}
