<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

final readonly class ProfileChunkSplit
{
    public function __construct(
        public int $chunkIndex,
        public int $charStart,
        public int $charEnd,
        public ?int $overlapBeforeStart,
        public ?int $overlapBeforeEnd,
        public string $coreTextHash,
    ) {}

    public function coreLength(): int
    {
        return $this->charEnd - $this->charStart;
    }
}
