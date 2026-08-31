<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class ProviderAttemptMetadata
{
    public function __construct(
        public string $provider,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public ?int $latencyMs = null,
        public ?string $finishReason = null,
        public ?string $safeRequestId = null,
    ) {}
}
