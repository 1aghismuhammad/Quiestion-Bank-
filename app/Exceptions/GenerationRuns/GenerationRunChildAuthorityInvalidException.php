<?php

declare(strict_types=1);

namespace App\Exceptions\GenerationRuns;

use App\Enums\GenerationErrorCode;
use RuntimeException;

class GenerationRunChildAuthorityInvalidException extends RuntimeException
{
    public function __construct(
        public readonly GenerationErrorCode $errorCode,
        public readonly bool $workerMayFinalizeFailure,
    ) {
        parent::__construct($errorCode->userMessage());
    }
}
