<?php

declare(strict_types=1);

namespace App\Events;

final class GenerationRunChildDispatchRequested
{
    public function __construct(
        public int $generationId,
        public string $executionToken,
    ) {}
}
