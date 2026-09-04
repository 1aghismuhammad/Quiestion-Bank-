<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileElementOrigin: string
{
    case EXTRACTED = 'extracted';
    case SUGGESTED = 'suggested';
}
