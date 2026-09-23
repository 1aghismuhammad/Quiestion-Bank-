<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintImportGroundingProviderResult
{
    /**
     * @param  list<array{index: int, fields: array<string, array{status: string, profile_element_ids: list<int>}>}>  $candidates
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $candidates,
        public array $warnings,
        public BlueprintProviderAttemptMetadata $metadata,
    ) {}
}
