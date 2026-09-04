<?php

declare(strict_types=1);

namespace App\Exceptions\MaterialProfiles;

use RuntimeException;

class MaterialProfileAttemptBudgetExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly int $profileStepId,
        string $message = 'The material profile provider attempt budget is exhausted for this step.',
    ) {
        parent::__construct($message);
    }
}
