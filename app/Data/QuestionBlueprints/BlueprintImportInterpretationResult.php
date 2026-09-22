<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

use App\Enums\BlueprintImportDocumentKind;

final readonly class BlueprintImportInterpretationResult
{
    public const SCHEMA_VERSION = 'blueprint-import-interpretation-result-v1';

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public BlueprintImportDocumentKind $documentKind,
        public array $candidates,
        public array $warnings,
        public array $metadata,
    ) {}

    /**
     * @return array{
     *     schema_version: string,
     *     document_kind: string,
     *     candidates: list<array<string, mixed>>,
     *     warnings: list<string>,
     *     metadata: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'document_kind' => $this->documentKind->value,
            'candidates' => $this->candidates,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
        ];
    }
}
