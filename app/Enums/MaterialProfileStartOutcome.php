<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileStartOutcome: string
{
    case Created = 'created';
    case Reused = 'reused';
}
