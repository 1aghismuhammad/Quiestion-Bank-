<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintSource: string
{
    case Manual = 'manual';
    case Ai = 'ai';
}
