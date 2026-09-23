<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

use App\Enums\BlueprintImportGroundingCandidateStatus;

final readonly class BlueprintImportGroundingResult
{
    public const SCHEMA_VERSION = 'blueprint-import-grounding-result-v1';

    /**
     * @param  array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}  $fingerprint
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $groundedProfileVersionId,
        public string $interpretationSchemaVersion,
        public string $interpretationResultSha256,
        public array $fingerprint,
        public BlueprintImportGroundingCandidateStatus $documentRollup,
        public array $candidates,
        public array $warnings,
        public array $metadata,
    ) {}

    /**
     * @return array{
     *     schema_version: string,
     *     grounded_profile_version_id: int,
     *     interpretation_schema_version: string,
     *     interpretation_result_sha256: string,
     *     fingerprint: array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string},
     *     document_rollup: string,
     *     candidates: list<array<string, mixed>>,
     *     warnings: list<string>,
     *     metadata: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'grounded_profile_version_id' => $this->groundedProfileVersionId,
            'interpretation_schema_version' => $this->interpretationSchemaVersion,
            'interpretation_result_sha256' => $this->interpretationResultSha256,
            'fingerprint' => $this->fingerprint,
            'document_rollup' => $this->documentRollup->value,
            'candidates' => $this->candidates,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
        ];
    }
}
