<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintProviderAttemptMetadata
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $promptVersion,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public ?int $latencyMs = null,
    ) {}
}
