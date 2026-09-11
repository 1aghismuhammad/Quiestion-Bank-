<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintFillElementRef
{
    public function __construct(
        public string $ref,
        public string $kind,
        public string $text,
    ) {}
}
