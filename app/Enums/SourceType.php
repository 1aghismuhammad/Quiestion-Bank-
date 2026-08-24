<?php

declare(strict_types=1);

namespace App\Enums;

enum SourceType: string
{
    case UPLOAD = 'upload';
    case TEXT = 'text';
}
