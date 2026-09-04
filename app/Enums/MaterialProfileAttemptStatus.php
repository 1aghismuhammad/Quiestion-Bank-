<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileAttemptStatus: string
{
    case STARTED = 'started';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
