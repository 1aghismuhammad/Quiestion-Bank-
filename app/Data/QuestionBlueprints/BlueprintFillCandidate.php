<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintFillCandidate
{
    /**
     * @param  list<array<string, mixed>>  $contexts
     */
    public function __construct(
        public mixed $objective,
        public mixed $topic,
        public mixed $indicator,
        public mixed $cognitiveLevel,
        public mixed $difficulty,
        public mixed $requestedCount,
        public array $contexts = [],
    ) {}
}
