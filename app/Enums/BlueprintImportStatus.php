<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintImportStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case EXTRACTED = 'extracted';
    case FAILED = 'failed';
}
