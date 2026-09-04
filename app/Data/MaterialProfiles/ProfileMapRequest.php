<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

/**
 * The complete input a map provider call is allowed to see: one canonical chunk
 * core plus at most `chunk_overlap_chars` of preceding overlap.
 */
final readonly class ProfileMapRequest
{
    public function __construct(
        public int $profileVersionId,
        public int $chunkIndex,
        public string $coreText,
        public ?string $overlapText,
        public int $coreCharStart,
        public int $coreCharEnd,
        public string $model,
        public string $promptVersion,
    ) {}

    public function coreLength(): int
    {
        return mb_strlen($this->coreText, 'UTF-8');
    }

    public function overlapLength(): int
    {
        return $this->overlapText === null ? 0 : mb_strlen($this->overlapText, 'UTF-8');
    }
}
