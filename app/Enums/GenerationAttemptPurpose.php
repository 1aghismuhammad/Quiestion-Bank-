<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationAttemptPurpose: string
{
    case INITIAL = 'initial';
    case REPAIR = 'repair';
}
