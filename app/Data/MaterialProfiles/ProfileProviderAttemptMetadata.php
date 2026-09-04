<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

use App\Enums\MaterialProfileStepPurpose;

/**
 * Sanitized provider-call telemetry. Deliberately carries no prompt, no response
 * body, and no credential material so that it is safe to persist verbatim.
 */
final readonly class ProfileProviderAttemptMetadata
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $promptVersion,
        public MaterialProfileStepPurpose $purpose,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public ?int $latencyMs = null,
    ) {}
}
