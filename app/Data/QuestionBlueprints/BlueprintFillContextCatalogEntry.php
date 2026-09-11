<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;

final readonly class BlueprintFillContextCatalogEntry
{
    public function __construct(
        public string $ref,
        public string $kind,
        public string $excerpt,
        public int $canonicalStart,
        public int $canonicalEnd,
        public ?MaterialProfileElement $element = null,
        public ?MaterialProfileChunk $chunk = null,
    ) {}
}
