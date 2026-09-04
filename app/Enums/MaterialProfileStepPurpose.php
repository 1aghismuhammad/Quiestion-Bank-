<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileStepPurpose: string
{
    case MAP = 'map';
    case REDUCE = 'reduce';
}
