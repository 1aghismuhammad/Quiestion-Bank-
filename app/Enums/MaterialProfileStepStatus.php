<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileStepStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::READY || $this === self::FAILED;
    }
}
