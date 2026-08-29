<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class GenerationUsageSnapshot
{
    public function __construct(
        public int $allowance,
        public int $consumed,
        public int $reserved,
        public int $available,
    ) {}
}
