<?php

declare(strict_types=1);

namespace App\Exceptions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;
use RuntimeException;

/**
 * Sanitized provider boundary failure.
 *
 * Only the attempt error code is ever persisted; the message stays in memory for
 * local diagnostics and is never written to the database or exposed to an owner.
 */
abstract class MaterialProfileProviderException extends RuntimeException
{
    public function __construct(
        public readonly MaterialProfileAttemptErrorCode $attemptErrorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    abstract public function isRetryable(): bool;
}
