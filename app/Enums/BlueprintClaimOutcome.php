<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintClaimOutcome: string
{
    case Claimed = 'claimed';
    case Resumed = 'resumed';
    case Duplicate = 'duplicate';
    case Terminal = 'terminal';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function shouldRun(): bool
    {
        return $this === self::Claimed || $this === self::Resumed;
    }
}
