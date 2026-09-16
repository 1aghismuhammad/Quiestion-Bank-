<?php

declare(strict_types=1);

namespace App\Support\QuestionSets;

use InvalidArgumentException;

final class QuestionSetSourceExclusive
{
    public static function assert(?int $generationId, ?int $generationRunId): void
    {
        if ($generationId !== null && $generationRunId !== null) {
            throw new InvalidArgumentException(
                'A Question Set cannot have both generation_id and generation_run_id.',
            );
        }
    }
}
