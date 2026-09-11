<?php

declare(strict_types=1);

namespace App\Data\Generations;

use App\Enums\AssessmentType;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;

/**
 * Immutable Blueprint-row snapshot for a Generation Run child. Legacy
 * non-Blueprint generation leaves this null.
 */
final readonly class BlueprintGenerationContext
{
    public function __construct(
        public string $objective,
        public string $topic,
        public string $indicator,
        public CognitiveLevel $cognitiveLevel,
        public DifficultyLevel $difficulty,
        public AssessmentType $assessmentType,
        public int $requestedCount,
    ) {}
}
