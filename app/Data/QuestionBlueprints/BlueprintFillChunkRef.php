<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintFillChunkRef
{
    public function __construct(
        public string $ref,
        public int $index,
        public string $excerpt,
    ) {}
}
