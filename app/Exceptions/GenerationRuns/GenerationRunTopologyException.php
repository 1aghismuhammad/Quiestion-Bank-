<?php

declare(strict_types=1);

namespace App\Exceptions\GenerationRuns;

use App\Enums\GenerationErrorCode;
use RuntimeException;

class GenerationRunTopologyException extends RuntimeException
{
    public function __construct(
        public readonly GenerationErrorCode $errorCode = GenerationErrorCode::TopologyInvalid,
        string $message = 'The generation run topology is invalid.',
    ) {
        parent::__construct($message);
    }
}
