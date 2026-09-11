<?php

declare(strict_types=1);

namespace App\Exceptions\QuestionBlueprints;

use App\Enums\BlueprintAttemptErrorCode;

class BlueprintMalformedResponseException extends BlueprintProviderException
{
    public function __construct(string $message = 'The blueprint provider returned malformed output.')
    {
        parent::__construct(BlueprintAttemptErrorCode::SchemaInvalid, $message);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
