<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
