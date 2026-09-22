<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

use Illuminate\Support\Carbon;

/**
 * Allowlisted owner-facing presentation for one Blueprint Import.
 * Deliberately omits structured_document, full result JSON, prompts, and secrets.
 */
final readonly class BlueprintImportOwnerReviewView
{
    /**
     * @param  list<array{
     *     index: int,
     *     fields: list<array{key: string, label: string, value: string, empty: bool}>,
     *     warnings: list<string>,
     *     unresolved: list<array{key: string, label: string}>,
     *     provenance: list<array{field_key: string, field_label: string, locations: list<string>}>
     * }>  $candidates
     * @param  list<string>  $topLevelWarnings
     */
    public function __construct(
        public int $importId,
        public string $originalFileName,
        public string $extractionStatus,
        public string $extractionStatusLabel,
        public ?string $interpretationStatus,
        public string $interpretationStatusLabel,
        public bool $inFlight,
        public bool $terminal,
        public bool $canRetry,
        public bool $resultValid,
        public ?string $presentationError,
        public ?string $documentKind,
        public ?string $documentKindLabel,
        public array $topLevelWarnings,
        public array $candidates,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?Carbon $createdAt,
        public ?Carbon $interpretationCompletedAt,
    ) {}

    public function isInFlight(): bool
    {
        return $this->inFlight;
    }
}
