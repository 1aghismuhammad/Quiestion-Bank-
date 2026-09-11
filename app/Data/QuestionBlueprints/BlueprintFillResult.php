<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintFillResult
{
    /**
     * @param  list<BlueprintFillCandidate>  $candidates
     */
    public function __construct(
        public array $candidates,
        public BlueprintProviderAttemptMetadata $metadata,
    ) {}
}
