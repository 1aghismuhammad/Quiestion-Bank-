<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileClaimOutcome: string
{
    case Claimed = 'claimed';
    case Resumed = 'resumed';
    case Duplicate = 'duplicate';
    case Expired = 'expired';
    case Terminal = 'terminal';
    case Revoked = 'revoked';
    case NotNextStep = 'not_next_step';
}
