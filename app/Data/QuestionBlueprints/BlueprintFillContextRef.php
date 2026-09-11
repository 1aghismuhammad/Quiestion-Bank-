<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintFillContextRef
{
    public function __construct(
        public string $ref,
        public string $kind,
        public string $label,
        public string $excerpt,
    ) {}
}
