<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class GenerationClaimResult
{
    public function __construct(
        public bool $shouldRun,
        public string $outcome,
    ) {}
}
