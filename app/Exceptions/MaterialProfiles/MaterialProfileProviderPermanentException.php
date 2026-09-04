<?php

declare(strict_types=1);

namespace App\Exceptions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;

class MaterialProfileProviderPermanentException extends MaterialProfileProviderException
{
    public function __construct(
        MaterialProfileAttemptErrorCode $attemptErrorCode = MaterialProfileAttemptErrorCode::ProviderHttp,
        string $message = 'The material profile provider rejected the request permanently.',
    ) {
        parent::__construct($attemptErrorCode, $message);
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
