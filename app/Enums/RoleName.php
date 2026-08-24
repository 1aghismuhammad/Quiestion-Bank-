<?php

declare(strict_types=1);

namespace App\Enums;

enum RoleName: string
{
    case ADMIN = 'ADMIN';
    case USER = 'USER';
}
