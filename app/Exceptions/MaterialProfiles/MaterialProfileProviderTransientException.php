<?php

declare(strict_types=1);

namespace App\Exceptions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;

class MaterialProfileProviderTransientException extends MaterialProfileProviderException
{
    public function __construct(
        MaterialProfileAttemptErrorCode $attemptErrorCode = MaterialProfileAttemptErrorCode::ProviderHttp,
        string $message = 'The material profile provider is temporarily unavailable.',
        ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($attemptErrorCode, $message, $retryAfterSeconds);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
