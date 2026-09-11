<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintAiFillStatus: string
{
    case None = 'none';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isInFlight(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
