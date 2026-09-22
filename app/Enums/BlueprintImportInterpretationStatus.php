<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintImportInterpretationStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case REVIEW_READY = 'review_ready';
    case FAILED = 'failed';
}
