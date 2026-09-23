<?php

declare(strict_types=1);

namespace App\Services\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportGroundingProviderResult;
use App\Data\QuestionBlueprints\BlueprintImportGroundingResult;
use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Enums\BlueprintImportGroundingCandidateStatus;
use App\Enums\BlueprintImportGroundingFieldStatus;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\QuestionBlueprints\BlueprintMalformedResponseException;
use App\Models\MaterialProfileElement;

class BlueprintImportGroundingResultBuilder
{
    public const FACTUAL_FIELDS = [
        'objective',
        'topic',
        'material',
        'indicator',
    ];

    /**
     * @param  array<string, mixed>  $interpretationResult
     * @param  array<int, MaterialProfileElement>  $elementsById
     * @param  array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}  $fingerprint
     * @param  array<string, mixed>  $metadata
     */
    public function build(
        BlueprintImportGroundingProviderResult $provider,
        array $interpretationResult,
        array $elementsById,
        int $profileVersionId,
        string $interpretationSha256,
        array $fingerprint,
        array $metadata,
    ): BlueprintImportGroundingResult {
        $interpretationCandidates = $interpretationResult['candidates'] ?? null;

        if (! is_array($interpretationCandidates) || ! array_is_list($interpretationCandidates)) {
            throw new BlueprintMalformedResponseException('The grounding interpretation candidates are invalid.');
        }

        if (! array_is_list($provider->candidates)) {
            throw new BlueprintMalformedResponseException('The grounding provider returned a non-list candidates payload.');
        }

        $maxCandidates = max(1, (int) config('question_blueprint.max_import_candidates', 100));

        if (count($interpretationCandidates) > $maxCandidates) {
            throw new BlueprintMalformedResponseException('The grounding interpretation returned too many candidates.');
        }

        $expectedSparseIndexes = [];

        foreach ($interpretationCandidates as $offset => $interpretationCandidate) {
            if (! is_array($interpretationCandidate)) {
                throw new BlueprintMalformedResponseException('The grounding interpretation candidate is invalid.');
            }

            if ($this->nonEmptyClaims($interpretationCandidate) !== []) {
                $expectedSparseIndexes[(int) $offset] = true;
            }
        }

        $providerByIndex = [];

        foreach ($provider->candidates as $providerCandidate) {
            if (! is_array($providerCandidate)) {
                throw new BlueprintMalformedResponseException('The grounding provider returned a non-object candidate.');
            }

            if (! array_key_exists('index', $providerCandidate) || ! array_key_exists('fields', $providerCandidate)) {
                throw new BlueprintMalformedResponseException('The grounding provider returned a candidate missing index or fields.');
            }

            $index = $this->requireInt($providerCandidate['index'], 'index');

            if (isset($providerByIndex[$index])) {
                throw new BlueprintMalformedResponseException('The grounding provider returned a duplicate candidate index.');
            }

            if (! isset($expectedSparseIndexes[$index])) {
                throw new BlueprintMalformedResponseException('The grounding provider returned an unexpected candidate index.');
            }

            $providerByIndex[$index] = $providerCandidate;
        }

        $expectedKeys = array_keys($expectedSparseIndexes);
        $providedKeys = array_keys($providerByIndex);
        sort($expectedKeys);
        sort($providedKeys);

        if ($providedKeys !== $expectedKeys) {
            throw new BlueprintMalformedResponseException('The grounding provider candidate indexes do not match the sparse claim set.');
        }

        $warnings = $this->stringList($provider->warnings);
        $built = [];

        foreach ($interpretationCandidates as $offset => $interpretationCandidate) {
            $index = (int) $offset;
            $claims = $this->nonEmptyClaims($interpretationCandidate);

            if ($claims === []) {
                $built[] = $this->buildNotApplicableCandidate($index, $interpretationCandidate);

                continue;
            }

            $built[] = $this->buildCandidate(
                $providerByIndex[$index]['fields'],
                $interpretationCandidate,
                $index,
                $elementsById,
                $profileVersionId,
            );
        }

        return new BlueprintImportGroundingResult(
            $profileVersionId,
            BlueprintImportInterpretationResult::SCHEMA_VERSION,
            $interpretationSha256,
            $fingerprint,
            $this->documentRollup($built),
            $built,
            $warnings,
            $metadata,
        );
    }

    /**
     * @param  array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}  $fingerprint
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $metadata
     */
    public function buildEmptyTaxonomyResult(
        int $profileVersionId,
        string $interpretationSha256,
        array $fingerprint,
        array $warnings = [],
        array $metadata = [],
    ): BlueprintImportGroundingResult {
        return new BlueprintImportGroundingResult(
            $profileVersionId,
            BlueprintImportInterpretationResult::SCHEMA_VERSION,
            $interpretationSha256,
            $fingerprint,
            BlueprintImportGroundingCandidateStatus::NOT_APPLICABLE,
            [],
            $warnings,
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $interpretationCandidate
     * @return array<string, string>
     */
    public function nonEmptyClaims(array $interpretationCandidate): array
    {
        $claims = [];

        foreach (self::FACTUAL_FIELDS as $field) {
            $raw = $interpretationCandidate['raw_'.$field] ?? null;

            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $claims[$field] = $raw;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $interpretationCandidate
     * @return array<string, mixed>
     */
    private function buildNotApplicableCandidate(int $index, array $interpretationCandidate): array
    {
        $fieldMap = [];

        foreach (self::FACTUAL_FIELDS as $field) {
            $claimRaw = $interpretationCandidate['raw_'.$field] ?? null;
            $claimRaw = is_string($claimRaw) ? $claimRaw : null;

            $fieldMap[$field] = [
                'claim_raw' => $claimRaw,
                'status' => BlueprintImportGroundingFieldStatus::NOT_APPLICABLE->value,
                'material_evidence' => [],
            ];
        }

        return [
            'index' => $index,
            'rollup' => BlueprintImportGroundingCandidateStatus::NOT_APPLICABLE->value,
            'import_provenance' => $this->importProvenance($interpretationCandidate),
            'fields' => $fieldMap,
        ];
    }

    /**
     * @param  array<string, mixed>  $interpretationCandidate
     * @return array<string, list<array<string, mixed>>>
     */
    private function importProvenance(array $interpretationCandidate): array
    {
        $sourceRefs = $interpretationCandidate['source_refs'] ?? [];

        if (! is_array($sourceRefs)) {
            throw new BlueprintMalformedResponseException('The grounding interpretation source_refs are invalid.');
        }

        $provenance = [];

        foreach (self::FACTUAL_FIELDS as $field) {
            $refs = $sourceRefs[$field] ?? [];

            if (! is_array($refs) || ! array_is_list($refs)) {
                throw new BlueprintMalformedResponseException('The grounding interpretation source_refs are invalid.');
            }

            $provenance[$field] = $refs;
        }

        return $provenance;
    }

    /**
     * @param  array<string, mixed>  $interpretationCandidate
     * @param  array<int, MaterialProfileElement>  $elementsById
     * @return array<string, mixed>
     */
    private function buildCandidate(
        mixed $fields,
        array $interpretationCandidate,
        int $index,
        array $elementsById,
        int $profileVersionId,
    ): array {
        if (! is_array($fields)) {
            throw new BlueprintMalformedResponseException('The grounding provider returned invalid candidate fields.');
        }

        $expectedClaims = $this->nonEmptyClaims($interpretationCandidate);
        $expectedKeys = array_keys($expectedClaims);
        $providedKeys = array_keys($fields);
        sort($expectedKeys);
        $sortedProvided = $providedKeys;
        sort($sortedProvided);

        if ($sortedProvided !== $expectedKeys) {
            throw new BlueprintMalformedResponseException('The grounding provider returned a sparse field set that does not match claims.');
        }

        foreach ($providedKeys as $key) {
            if (! is_string($key) || ! in_array($key, self::FACTUAL_FIELDS, true)) {
                throw new BlueprintMalformedResponseException('The grounding provider returned an unknown factual field.');
            }
        }

        $fieldMap = [];

        foreach (self::FACTUAL_FIELDS as $field) {
            $claimRaw = $interpretationCandidate['raw_'.$field] ?? null;
            $claimRaw = is_string($claimRaw) ? $claimRaw : null;
            $empty = $claimRaw === null || $claimRaw === '';

            if ($empty) {
                $fieldMap[$field] = [
                    'claim_raw' => $claimRaw,
                    'status' => BlueprintImportGroundingFieldStatus::NOT_APPLICABLE->value,
                    'material_evidence' => [],
                ];

                continue;
            }

            $providerField = $fields[$field];

            if (! is_array($providerField)
                || ! array_key_exists('status', $providerField)
                || ! array_key_exists('profile_element_ids', $providerField)) {
                throw new BlueprintMalformedResponseException('The grounding provider returned an incomplete field payload.');
            }

            $status = BlueprintImportGroundingFieldStatus::tryFrom((string) $providerField['status']);

            if ($status === null || $status === BlueprintImportGroundingFieldStatus::NOT_APPLICABLE) {
                throw new BlueprintMalformedResponseException('The grounding provider returned an invalid field status.');
            }

            $ids = $this->normalizeElementIds($providerField['profile_element_ids']);
            $this->assertFieldInvariants($status, $ids);

            $fieldMap[$field] = [
                'claim_raw' => $claimRaw,
                'status' => $status->value,
                'material_evidence' => $this->buildEvidence($ids, $elementsById, $profileVersionId),
            ];
        }

        $rollup = $this->candidateRollup($fieldMap);

        return [
            'index' => $index,
            'rollup' => $rollup->value,
            'import_provenance' => $this->importProvenance($interpretationCandidate),
            'fields' => $fieldMap,
        ];
    }

    /**
     * @param  list<int>  $ids
     */
    private function assertFieldInvariants(BlueprintImportGroundingFieldStatus $status, array $ids): void
    {
        $count = count($ids);
        $maxRefs = max(1, (int) config('question_blueprint.import_grounding_max_refs_per_claim', 4));

        if ($count > $maxRefs) {
            throw new BlueprintMalformedResponseException('The grounding provider returned too many profile_element_ids.');
        }

        if ($status === BlueprintImportGroundingFieldStatus::NOT_APPLICABLE) {
            throw new BlueprintMalformedResponseException('The grounding provider must not emit not_applicable.');
        }

        if ($status === BlueprintImportGroundingFieldStatus::GROUNDED && ($count < 1 || $count > $maxRefs)) {
            throw new BlueprintMalformedResponseException('Grounded fields require 1..4 profile_element_ids.');
        }

        if ($status === BlueprintImportGroundingFieldStatus::UNRESOLVED && $count !== 0) {
            throw new BlueprintMalformedResponseException('Unresolved fields require zero profile_element_ids.');
        }

        if ($status === BlueprintImportGroundingFieldStatus::AMBIGUOUS && ($count < 2 || $count > $maxRefs)) {
            throw new BlueprintMalformedResponseException('Ambiguous fields require 2..4 profile_element_ids.');
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, MaterialProfileElement>  $elementsById
     * @return list<array{
     *     profile_element_id: int,
     *     source_chunk_id: ?int,
     *     char_start: ?int,
     *     char_end: ?int,
     *     evidence_excerpt: ?string,
     *     evidence_locator: ?string
     * }>
     */
    private function buildEvidence(array $ids, array $elementsById, int $profileVersionId): array
    {
        $evidence = [];

        foreach ($ids as $id) {
            $element = $elementsById[$id] ?? null;

            if (! $element instanceof MaterialProfileElement) {
                throw new BlueprintMalformedResponseException('The grounding provider referenced an unknown profile_element_id.');
            }

            if ($element->origin !== MaterialProfileElementOrigin::EXTRACTED) {
                throw new BlueprintMalformedResponseException('The grounding provider referenced a non-extracted profile_element_id.');
            }

            if ((int) $element->profile_version_id !== $profileVersionId) {
                throw new BlueprintMalformedResponseException('The grounding provider referenced a foreign profile_element_id.');
            }

            $evidence[] = [
                'profile_element_id' => (int) $element->profile_element_id,
                'source_chunk_id' => $element->source_chunk_id === null ? null : (int) $element->source_chunk_id,
                'char_start' => $element->char_start === null ? null : (int) $element->char_start,
                'char_end' => $element->char_end === null ? null : (int) $element->char_end,
                'evidence_excerpt' => $element->evidence_excerpt === null ? null : (string) $element->evidence_excerpt,
                'evidence_locator' => $element->evidence_locator === null ? null : (string) $element->evidence_locator,
            ];
        }

        return $evidence;
    }

    /**
     * @param  array<string, array{claim_raw: ?string, status: string, material_evidence: list<array<string, mixed>>}>  $fields
     */
    private function candidateRollup(array $fields): BlueprintImportGroundingCandidateStatus
    {
        $grounded = 0;
        $unresolvedOrAmbiguous = 0;
        $notApplicable = 0;

        foreach (self::FACTUAL_FIELDS as $field) {
            $status = BlueprintImportGroundingFieldStatus::from($fields[$field]['status']);

            match ($status) {
                BlueprintImportGroundingFieldStatus::GROUNDED => $grounded++,
                BlueprintImportGroundingFieldStatus::UNRESOLVED,
                BlueprintImportGroundingFieldStatus::AMBIGUOUS => $unresolvedOrAmbiguous++,
                BlueprintImportGroundingFieldStatus::NOT_APPLICABLE => $notApplicable++,
            };
        }

        if ($notApplicable === count(self::FACTUAL_FIELDS)) {
            return BlueprintImportGroundingCandidateStatus::NOT_APPLICABLE;
        }

        if ($grounded >= 1 && $unresolvedOrAmbiguous === 0) {
            return BlueprintImportGroundingCandidateStatus::GROUNDED;
        }

        if ($grounded >= 1 && $unresolvedOrAmbiguous >= 1) {
            return BlueprintImportGroundingCandidateStatus::PARTIAL;
        }

        return BlueprintImportGroundingCandidateStatus::UNGROUNDED;
    }

    /**
     * @param  list<array{rollup: string}>  $candidates
     */
    private function documentRollup(array $candidates): BlueprintImportGroundingCandidateStatus
    {
        if ($candidates === []) {
            return BlueprintImportGroundingCandidateStatus::NOT_APPLICABLE;
        }

        $nonNa = [];

        foreach ($candidates as $candidate) {
            $status = BlueprintImportGroundingCandidateStatus::from($candidate['rollup']);

            if ($status !== BlueprintImportGroundingCandidateStatus::NOT_APPLICABLE) {
                $nonNa[] = $status;
            }
        }

        if ($nonNa === []) {
            return BlueprintImportGroundingCandidateStatus::NOT_APPLICABLE;
        }

        $hasGrounded = false;
        $hasUngrounded = false;

        foreach ($nonNa as $status) {
            if ($status === BlueprintImportGroundingCandidateStatus::PARTIAL) {
                return BlueprintImportGroundingCandidateStatus::PARTIAL;
            }

            if ($status === BlueprintImportGroundingCandidateStatus::GROUNDED) {
                $hasGrounded = true;
            }

            if ($status === BlueprintImportGroundingCandidateStatus::UNGROUNDED) {
                $hasUngrounded = true;
            }
        }

        if ($hasGrounded && $hasUngrounded) {
            return BlueprintImportGroundingCandidateStatus::PARTIAL;
        }

        if ($hasGrounded) {
            return BlueprintImportGroundingCandidateStatus::GROUNDED;
        }

        return BlueprintImportGroundingCandidateStatus::UNGROUNDED;
    }

    /**
     * @return list<int>
     */
    private function normalizeElementIds(mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new BlueprintMalformedResponseException('The grounding provider returned invalid profile_element_ids.');
        }

        $normalized = [];
        $seen = [];

        foreach ($ids as $id) {
            $value = $this->requireInt($id, 'profile_element_ids');

            if (isset($seen[$value])) {
                throw new BlueprintMalformedResponseException('The grounding provider returned duplicate profile_element_ids.');
            }

            $seen[$value] = true;
            $normalized[] = $value;
        }

        return $normalized;
    }

    private function requireInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        throw new BlueprintMalformedResponseException('The grounding provider returned an invalid '.$field.'.');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new BlueprintMalformedResponseException('The grounding provider returned invalid string lists.');
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new BlueprintMalformedResponseException('The grounding provider returned a non-string warning.');
            }

            $items[] = $item;
        }

        return array_values($items);
    }
}
