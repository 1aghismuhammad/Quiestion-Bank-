<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileOwnerState: string
{
    case None = 'none';
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Stale = 'stale';

    public function isTerminal(): bool
    {
        return $this !== self::Queued && $this !== self::Processing;
    }
}
