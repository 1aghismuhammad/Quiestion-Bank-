<?php

declare(strict_types=1);

namespace App\Exceptions\QuestionBlueprints;

class BlueprintProviderPermanentException extends BlueprintProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
