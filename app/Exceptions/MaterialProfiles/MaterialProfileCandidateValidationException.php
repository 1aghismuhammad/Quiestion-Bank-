<?php

declare(strict_types=1);

namespace App\Exceptions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;

/**
 * Raised when a decoded provider result fails server-side evidence or shape
 * validation. The complete response is rejected; nothing is persisted.
 */
class MaterialProfileCandidateValidationException extends MaterialProfileProviderException
{
    public function __construct(string $message = 'The material profile provider result failed validation.')
    {
        parent::__construct(MaterialProfileAttemptErrorCode::ValidationFailed, $message);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
