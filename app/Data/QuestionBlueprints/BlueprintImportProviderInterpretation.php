<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintImportProviderInterpretation
{
    /**
     * @param  list<array{bindings: array<string, list<array<string, mixed>>>, warnings: list<string>, unresolved: list<string>}>  $candidates
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $documentKind,
        public array $candidates,
        public array $warnings,
        public BlueprintProviderAttemptMetadata $metadata,
    ) {}
}
