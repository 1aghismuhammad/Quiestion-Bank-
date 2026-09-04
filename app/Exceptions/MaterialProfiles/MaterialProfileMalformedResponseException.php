<?php

declare(strict_types=1);

namespace App\Exceptions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;

class MaterialProfileMalformedResponseException extends MaterialProfileProviderException
{
    public function __construct(string $message = 'The material profile provider returned malformed output.')
    {
        parent::__construct(MaterialProfileAttemptErrorCode::SchemaInvalid, $message);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
