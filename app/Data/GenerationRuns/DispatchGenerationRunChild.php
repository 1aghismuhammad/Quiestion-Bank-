<?php

declare(strict_types=1);

namespace App\Data\GenerationRuns;

final readonly class DispatchGenerationRunChild
{
    public function __construct(
        public int $generationId,
        public string $executionToken,
    ) {}
}
