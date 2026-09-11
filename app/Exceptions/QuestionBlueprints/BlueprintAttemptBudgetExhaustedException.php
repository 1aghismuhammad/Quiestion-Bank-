<?php

declare(strict_types=1);

namespace App\Exceptions\QuestionBlueprints;

use RuntimeException;

class BlueprintAttemptBudgetExhaustedException extends RuntimeException
{
    public function __construct(public readonly int $blueprintId)
    {
        parent::__construct('The blueprint provider attempt budget is exhausted.');
    }
}
