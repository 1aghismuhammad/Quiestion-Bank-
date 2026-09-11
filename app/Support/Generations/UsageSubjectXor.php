<?php

declare(strict_types=1);

namespace App\Support\Generations;

use InvalidArgumentException;

final class UsageSubjectXor
{
    public static function assert(?int $generationId, ?int $generationRunId): void
    {
        $hasGeneration = $generationId !== null;
        $hasRun = $generationRunId !== null;

        if ($hasGeneration === $hasRun) {
            throw new InvalidArgumentException(
                'Usage rows require exactly one of generation_id or generation_run_id.',
            );
        }
    }
}
