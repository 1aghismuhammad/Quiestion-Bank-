<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Data\QuestionBlueprints\BlueprintImportOwnerReviewView;
use App\Enums\BlueprintImportDocumentKind;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Models\QuestionBlueprintImport;
use App\Services\QuestionBlueprints\BlueprintImportInterpretationResultBuilder;
use Illuminate\Support\Carbon;

class ResolveBlueprintImportOwnerReview
{
    private const EMPTY_VALUE = 'Belum teridentifikasi';

    private const PRESENTATION_ERROR = 'Hasil interpretasi tidak dapat ditampilkan dengan aman. Data tersimpan tidak valid atau tidak dikenali.';

    /**
     * @var list<string>
     */
    private const CANONICAL_NULL_KEYS = [
        'cognitive_level',
        'difficulty',
        'question_type',
        'assessment_type',
        'requested_count',
    ];

    /**
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'objective' => 'Tujuan pembelajaran',
        'topic' => 'Topik',
        'material' => 'Materi',
        'indicator' => 'Indikator',
        'cognitive_level' => 'Level kognitif',
        'difficulty' => 'Tingkat kesulitan',
        'question_type' => 'Bentuk soal',
        'assessment_type' => 'Jenis asesmen',
        'numbering' => 'Penomoran',
        'extra' => 'Informasi tambahan',
    ];

    /**
     * @var array<string, string>
     */
    private const DOCUMENT_KIND_LABELS = [
        'blueprint_like' => 'Kisi-kisi terdeteksi',
        'matrix_incomplete' => 'Struktur kisi-kisi belum lengkap',
        'taxonomy_non_blueprint' => 'Dokumen taksonomi, bukan kisi-kisi',
        'ambiguous' => 'Struktur dokumen belum dapat dipastikan',
        'empty' => 'Tidak ada isi yang dapat ditinjau',
    ];

    public function handle(QuestionBlueprintImport $import): BlueprintImportOwnerReviewView
    {
        $extraction = $import->status;
        $interpretation = $import->interpretation_status;

        $extractionValue = $extraction->value;
        $interpretationValue = $interpretation?->value;

        if ($interpretation !== null && $extraction !== BlueprintImportStatus::EXTRACTED) {
            return $this->failClosedView(
                $import,
                $extractionValue,
                $interpretationValue,
                $this->extractionLabel($extraction),
                $this->interpretationLabel($interpretation),
            );
        }

        $inFlight = $this->isInFlight($extraction, $interpretation);
        $canRetry = $extraction === BlueprintImportStatus::EXTRACTED
            && $interpretation === BlueprintImportInterpretationStatus::FAILED;

        $documentKind = null;
        $documentKindLabel = null;
        $topLevelWarnings = [];
        $candidates = [];
        $resultValid = true;
        $presentationError = null;

        if ($interpretation === BlueprintImportInterpretationStatus::REVIEW_READY) {
            $parsed = $this->parseResult($import->interpretation_result);

            if ($parsed === null) {
                $resultValid = false;
                $presentationError = self::PRESENTATION_ERROR;
            } else {
                $documentKind = $parsed['document_kind'];
                $documentKindLabel = self::DOCUMENT_KIND_LABELS[$documentKind] ?? $documentKind;
                $topLevelWarnings = $parsed['warnings'];
                $candidates = $parsed['candidates'];
            }
        }

        return new BlueprintImportOwnerReviewView(
            importId: (int) $import->import_id,
            originalFileName: (string) $import->original_file_name,
            extractionStatus: $extractionValue,
            extractionStatusLabel: $this->extractionLabel($extraction),
            interpretationStatus: $interpretationValue,
            interpretationStatusLabel: $this->interpretationLabel($interpretation),
            inFlight: $inFlight,
            terminal: ! $inFlight,
            canRetry: $canRetry,
            resultValid: $resultValid,
            presentationError: $presentationError,
            documentKind: $documentKind,
            documentKindLabel: $documentKindLabel,
            topLevelWarnings: $topLevelWarnings,
            candidates: $candidates,
            errorCode: $import->interpretation_error_code,
            errorMessage: $import->interpretation_error_message,
            createdAt: $import->created_at instanceof Carbon ? $import->created_at : null,
            interpretationCompletedAt: $import->interpretation_completed_at instanceof Carbon
                ? $import->interpretation_completed_at
                : null,
        );
    }

    private function failClosedView(
        QuestionBlueprintImport $import,
        string $extractionValue,
        ?string $interpretationValue,
        string $extractionLabel,
        string $interpretationLabel,
    ): BlueprintImportOwnerReviewView {
        return new BlueprintImportOwnerReviewView(
            importId: (int) $import->import_id,
            originalFileName: (string) $import->original_file_name,
            extractionStatus: $extractionValue,
            extractionStatusLabel: $extractionLabel,
            interpretationStatus: $interpretationValue,
            interpretationStatusLabel: $interpretationLabel,
            inFlight: false,
            terminal: true,
            canRetry: false,
            resultValid: false,
            presentationError: self::PRESENTATION_ERROR,
            documentKind: null,
            documentKindLabel: null,
            topLevelWarnings: [],
            candidates: [],
            errorCode: $import->interpretation_error_code,
            errorMessage: $import->interpretation_error_message,
            createdAt: $import->created_at instanceof Carbon ? $import->created_at : null,
            interpretationCompletedAt: $import->interpretation_completed_at instanceof Carbon
                ? $import->interpretation_completed_at
                : null,
        );
    }

    private function isInFlight(
        BlueprintImportStatus $extraction,
        ?BlueprintImportInterpretationStatus $interpretation,
    ): bool {
        if (in_array($extraction, [BlueprintImportStatus::PENDING, BlueprintImportStatus::PROCESSING], true)) {
            return true;
        }

        return in_array($interpretation, [
            BlueprintImportInterpretationStatus::QUEUED,
            BlueprintImportInterpretationStatus::PROCESSING,
        ], true);
    }

    private function extractionLabel(BlueprintImportStatus $status): string
    {
        return match ($status) {
            BlueprintImportStatus::PENDING => 'Menunggu ekstraksi',
            BlueprintImportStatus::PROCESSING => 'Sedang diekstraksi',
            BlueprintImportStatus::EXTRACTED => 'Ekstraksi selesai',
            BlueprintImportStatus::FAILED => 'Ekstraksi gagal',
        };
    }

    private function interpretationLabel(?BlueprintImportInterpretationStatus $status): string
    {
        if ($status === null) {
            return 'Belum diinterpretasi';
        }

        return match ($status) {
            BlueprintImportInterpretationStatus::QUEUED => 'Interpretasi mengantri',
            BlueprintImportInterpretationStatus::PROCESSING => 'Interpretasi diproses',
            BlueprintImportInterpretationStatus::REVIEW_READY => 'Siap ditinjau',
            BlueprintImportInterpretationStatus::FAILED => 'Interpretasi gagal',
        };
    }

    /**
     * @return array{
     *     document_kind: string,
     *     warnings: list<string>,
     *     candidates: list<array{
     *         index: int,
     *         fields: list<array{key: string, label: string, value: string, empty: bool}>,
     *         warnings: list<string>,
     *         unresolved: list<array{key: string, label: string}>,
     *         provenance: list<array{field_key: string, field_label: string, locations: list<string>}>
     *     }>
     * }|null
     */
    private function parseResult(mixed $result): ?array
    {
        if (! is_array($result)) {
            return null;
        }

        if (($result['schema_version'] ?? null) !== BlueprintImportInterpretationResult::SCHEMA_VERSION) {
            return null;
        }

        if (! array_key_exists('document_kind', $result)) {
            return null;
        }

        $kindRaw = $result['document_kind'];

        if (! is_string($kindRaw) || BlueprintImportDocumentKind::tryFrom($kindRaw) === null) {
            return null;
        }

        if (! array_key_exists('warnings', $result)) {
            return null;
        }

        $warnings = $this->stringList($result['warnings']);

        if ($warnings === null) {
            return null;
        }

        if (! array_key_exists('candidates', $result)) {
            return null;
        }

        $candidatesRaw = $result['candidates'];

        if (! is_array($candidatesRaw) || ! array_is_list($candidatesRaw)) {
            return null;
        }

        $maxCandidates = max(1, (int) config('question_blueprint.max_import_candidates', 100));

        if (count($candidatesRaw) > $maxCandidates) {
            return null;
        }

        if (
            in_array($kindRaw, [
                BlueprintImportDocumentKind::TaxonomyNonBlueprint->value,
                BlueprintImportDocumentKind::Empty->value,
            ], true)
            && $candidatesRaw !== []
        ) {
            return null;
        }

        $mapped = [];

        foreach ($candidatesRaw as $index => $candidate) {
            $parsedCandidate = $this->parseCandidate($candidate, (int) $index);

            if ($parsedCandidate === null) {
                return null;
            }

            $mapped[] = $parsedCandidate;
        }

        return [
            'document_kind' => $kindRaw,
            'warnings' => $warnings,
            'candidates' => $mapped,
        ];
    }

    /**
     * @return array{
     *     index: int,
     *     fields: list<array{key: string, label: string, value: string, empty: bool}>,
     *     warnings: list<string>,
     *     unresolved: list<array{key: string, label: string}>,
     *     provenance: list<array{field_key: string, field_label: string, locations: list<string>}>
     * }|null
     */
    private function parseCandidate(mixed $candidate, int $index): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        foreach (self::CANONICAL_NULL_KEYS as $canonicalKey) {
            if (! array_key_exists($canonicalKey, $candidate) || $candidate[$canonicalKey] !== null) {
                return null;
            }
        }

        if (! array_key_exists('source_refs', $candidate) || ! is_array($candidate['source_refs'])) {
            return null;
        }

        $sourceRefs = $candidate['source_refs'];
        $fields = [];
        $provenance = [];
        $totalRefCount = 0;

        foreach (BlueprintImportInterpretationResultBuilder::SEMANTIC_KEYS as $key) {
            $rawKey = 'raw_'.$key;

            if (! array_key_exists($rawKey, $candidate)) {
                return null;
            }

            $rawValue = $candidate[$rawKey];

            if ($rawValue !== null && ! is_string($rawValue)) {
                return null;
            }

            $empty = $rawValue === null || $rawValue === '';
            $fields[] = [
                'key' => $key,
                'label' => self::FIELD_LABELS[$key],
                'value' => $empty ? self::EMPTY_VALUE : $rawValue,
                'empty' => $empty,
            ];

            if (! array_key_exists($key, $sourceRefs)) {
                return null;
            }

            $refs = $sourceRefs[$key];

            if (! is_array($refs) || ! array_is_list($refs)) {
                return null;
            }

            $locations = [];

            foreach ($refs as $ref) {
                $label = $this->provenanceLabel($ref);

                if ($label === null) {
                    return null;
                }

                $locations[] = $label;
                $totalRefCount++;
            }

            if ($locations !== []) {
                $provenance[] = [
                    'field_key' => $key,
                    'field_label' => self::FIELD_LABELS[$key],
                    'locations' => $locations,
                ];
            }
        }

        if ($totalRefCount < 1) {
            return null;
        }

        if (! array_key_exists('warnings', $candidate)) {
            return null;
        }

        $warnings = $this->stringList($candidate['warnings']);

        if ($warnings === null) {
            return null;
        }

        if (! array_key_exists('unresolved', $candidate)) {
            return null;
        }

        $unresolvedRaw = $candidate['unresolved'];

        if (! is_array($unresolvedRaw) || ! array_is_list($unresolvedRaw)) {
            return null;
        }

        $unresolved = [];

        foreach ($unresolvedRaw as $marker) {
            if (! is_string($marker) || ! isset(self::FIELD_LABELS[$marker])) {
                return null;
            }

            $unresolved[] = [
                'key' => $marker,
                'label' => self::FIELD_LABELS[$marker],
            ];
        }

        return [
            'index' => $index,
            'fields' => $fields,
            'warnings' => $warnings,
            'unresolved' => $unresolved,
            'provenance' => $provenance,
        ];
    }

    private function provenanceLabel(mixed $ref): ?string
    {
        if (! is_array($ref)) {
            return null;
        }

        if (array_key_exists('paragraph_indexes', $ref) && ! $this->isValidParagraphIndexes($ref['paragraph_indexes'])) {
            return null;
        }

        $kind = $ref['kind'] ?? null;

        if ($kind === 'paragraph') {
            if (! array_key_exists('block_ordinal', $ref) || ! is_int($ref['block_ordinal']) || $ref['block_ordinal'] < 0) {
                return null;
            }

            if (! $this->roleIsAllowed($ref, ['paragraph'])) {
                return null;
            }

            $label = 'Paragraf '.($ref['block_ordinal'] + 1);

            if ($this->roleValue($ref) !== null) {
                $label .= ' ('.$this->roleLabel((string) $this->roleValue($ref)).')';
            }

            return $label;
        }

        if ($kind === 'cell') {
            foreach (['block_ordinal', 'table_index', 'row_index', 'cell_index'] as $coordinate) {
                if (! array_key_exists($coordinate, $ref) || ! is_int($ref[$coordinate]) || $ref[$coordinate] < 0) {
                    return null;
                }
            }

            if (! $this->roleIsAllowed($ref, ['cell', 'row_header', 'column_header', 'intersection'])) {
                return null;
            }

            $label = sprintf(
                'Tabel %d · Baris %d · Kolom %d',
                $ref['table_index'] + 1,
                $ref['row_index'] + 1,
                $ref['cell_index'] + 1,
            );

            if ($this->roleValue($ref) !== null) {
                $label .= ' ('.$this->roleLabel((string) $this->roleValue($ref)).')';
            }

            return $label;
        }

        return null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function roleIsAllowed(array $ref, array $allowed): bool
    {
        if (! array_key_exists('role', $ref) || $ref['role'] === null) {
            return true;
        }

        return is_string($ref['role']) && in_array($ref['role'], $allowed, true);
    }

    private function roleValue(array $ref): ?string
    {
        if (! array_key_exists('role', $ref) || $ref['role'] === null) {
            return null;
        }

        return is_string($ref['role']) ? $ref['role'] : null;
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'paragraph' => 'paragraf',
            'cell' => 'sel',
            'row_header' => 'header baris',
            'column_header' => 'header kolom',
            'intersection' => 'irisan',
            default => $role,
        };
    }

    private function isValidParagraphIndexes(mixed $indexes): bool
    {
        if (! is_array($indexes) || ! array_is_list($indexes)) {
            return false;
        }

        $previous = null;
        $seen = [];

        foreach ($indexes as $index) {
            if (! is_int($index) || $index < 0) {
                return false;
            }

            if (isset($seen[$index])) {
                return false;
            }

            if ($previous !== null && $index <= $previous) {
                return false;
            }

            $seen[$index] = true;
            $previous = $index;
        }

        return true;
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }

            $out[] = $item;
        }

        return $out;
    }
}
