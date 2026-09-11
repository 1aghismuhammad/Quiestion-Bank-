<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintAttemptStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
