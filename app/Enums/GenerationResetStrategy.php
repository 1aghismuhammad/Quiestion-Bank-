<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationResetStrategy: string
{
    case LIFETIME = 'lifetime';
    case MONTHLY = 'monthly';
}
