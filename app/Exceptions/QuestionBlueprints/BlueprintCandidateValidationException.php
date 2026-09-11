<?php

declare(strict_types=1);

namespace App\Exceptions\QuestionBlueprints;

use RuntimeException;

class BlueprintCandidateValidationException extends RuntimeException
{
    public function __construct(string $message = 'The blueprint provider candidates are invalid.')
    {
        parent::__construct($message);
    }
}
