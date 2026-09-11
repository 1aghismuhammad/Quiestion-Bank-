<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintRowOrigin: string
{
    case Manual = 'manual';
    case Extracted = 'extracted';
    case Suggested = 'suggested';
}
