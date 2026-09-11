<?php

declare(strict_types=1);

namespace App\Exceptions\GenerationRuns;

use App\Enums\GenerationRunErrorCode;
use RuntimeException;

class GenerationRunRejectedException extends RuntimeException
{
    public function __construct(public readonly GenerationRunErrorCode $errorCode)
    {
        parent::__construct($errorCode->userMessage());
    }
}
