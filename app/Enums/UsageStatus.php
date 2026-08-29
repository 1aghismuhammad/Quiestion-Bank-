<?php

declare(strict_types=1);

namespace App\Enums;

enum UsageStatus: string
{
    case RESERVED = 'reserved';
    case CHARGED = 'charged';
    case RELEASED = 'released';
}
