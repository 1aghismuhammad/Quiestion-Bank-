<?php

declare(strict_types=1);

namespace App\Exceptions\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use RuntimeException;

class MaterialProfileAttemptBudgetExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly int $profileStepId,
        public readonly ?MaterialProfileErrorCode $lastAttemptErrorCode = null,
        string $message = 'The material profile step exceeded its attempt budget.',
    ) {
        parent::__construct($message);
    }
}
