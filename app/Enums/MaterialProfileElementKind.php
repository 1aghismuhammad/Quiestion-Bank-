<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileElementKind: string
{
    case TOPIC = 'topic';
    case OBJECTIVE = 'objective';
    case INDICATOR = 'indicator';
    case OTHER = 'other';
}
