<?php

declare(strict_types=1);

namespace App\Exceptions\QuestionBlueprints;

class BlueprintProviderTransientException extends BlueprintProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
