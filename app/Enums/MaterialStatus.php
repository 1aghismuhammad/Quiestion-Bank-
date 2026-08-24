<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialStatus: string
{
    case DRAFT = 'draft';
    case READY = 'ready';
    case ARCHIVED = 'archived';
}
