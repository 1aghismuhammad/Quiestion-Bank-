<?php

declare(strict_types=1);

namespace App\Exceptions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use RuntimeException;

class BlueprintRejectedException extends RuntimeException
{
    public function __construct(public readonly BlueprintErrorCode $errorCode)
    {
        parent::__construct($errorCode->userMessage());
    }
}
