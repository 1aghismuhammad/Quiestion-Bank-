<?php

declare(strict_types=1);

namespace App\Exceptions\QuestionBlueprints;

use App\Enums\BlueprintAttemptErrorCode;
use RuntimeException;

abstract class BlueprintProviderException extends RuntimeException
{
    public function __construct(
        public readonly BlueprintAttemptErrorCode $attemptErrorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    abstract public function isRetryable(): bool;
}
