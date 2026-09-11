<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillContextCatalogEntry;
use App\Enums\BlueprintRowOrigin;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Exceptions\QuestionBlueprints\BlueprintCandidateValidationException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileVersion;

class ValidateBlueprintFillCandidates
{
    public function __construct(private AssertSimpleBlueprintShape $assertShape) {}

    /**
     * @param  list<BlueprintFillCandidate>  $candidates
     * @param  array<string, BlueprintFillContextCatalogEntry>  $catalog
     * @return list<array<string, mixed>>
     */
    public function handle(
        array $candidates,
        Material $material,
        MaterialProfileVersion $profile,
        array $catalog,
    ): array {
        $content = (string) $material->content;
        $rows = [];
        $maxContexts = max(1, (int) config('question_blueprint.max_contexts_per_row', 4));

        foreach ($candidates as $candidate) {
            $objective = $this->requiredText($candidate->objective);
            $topic = $this->requiredText($candidate->topic);
            $indicator = $this->requiredText($candidate->indicator);
            $cognitive = is_string($candidate->cognitiveLevel)
                ? CognitiveLevel::tryFrom($candidate->cognitiveLevel)
                : null;
            $difficulty = is_string($candidate->difficulty)
                ? DifficultyLevel::tryFrom($candidate->difficulty)
                : null;
            $requested = is_numeric($candidate->requestedCount) ? (int) $candidate->requestedCount : 0;

            if ($cognitive === null || $difficulty === null) {
                throw new BlueprintCandidateValidationException('A candidate uses an unsupported enumeration.');
            }

            if ($candidate->contexts === []) {
                throw new BlueprintCandidateValidationException('A candidate is missing context references.');
            }

            if (count($candidate->contexts) > $maxContexts) {
                throw new BlueprintCandidateValidationException('A candidate exceeds the context mapping budget.');
            }

            $contexts = [];
            $seenRefs = [];

            foreach ($candidate->contexts as $index => $context) {
                if (! is_array($context)) {
                    throw new BlueprintCandidateValidationException('A candidate context is not an object.');
                }

                $this->rejectProviderOwnedFields($context);

                $ref = is_string($context['context_ref'] ?? null) ? $context['context_ref'] : null;

                if ($ref === null || $ref === '') {
                    throw new BlueprintCandidateValidationException('A candidate is missing context references.');
                }

                if (isset($seenRefs[$ref])) {
                    throw new BlueprintCandidateValidationException('A candidate duplicated a context reference.');
                }

                $seenRefs[$ref] = true;

                $entry = $catalog[$ref] ?? null;

                if (! $entry instanceof BlueprintFillContextCatalogEntry) {
                    throw new BlueprintCandidateValidationException('A candidate referenced an unknown context.');
                }

                if ($entry->element !== null
                    && (int) $entry->element->profile_version_id !== (int) $profile->profile_version_id) {
                    throw new BlueprintCandidateValidationException('A candidate referenced a foreign profile element.');
                }

                if ($entry->chunk !== null
                    && (int) $entry->chunk->profile_version_id !== (int) $profile->profile_version_id) {
                    throw new BlueprintCandidateValidationException('A candidate referenced a foreign profile chunk.');
                }

                if ($entry->element !== null && $entry->chunk !== null) {
                    $elementChunkId = $entry->element->source_chunk_id === null
                        ? null
                        : (int) $entry->element->source_chunk_id;

                    if ($elementChunkId !== (int) $entry->chunk->profile_chunk_id) {
                        throw new BlueprintCandidateValidationException('A candidate mixed disagreeing profile references.');
                    }
                }

                $excerptLength = mb_strlen($entry->excerpt, 'UTF-8');
                $relativeStart = isset($context['excerpt_start']) && is_numeric($context['excerpt_start'])
                    ? (int) $context['excerpt_start']
                    : null;
                $relativeEnd = isset($context['excerpt_end']) && is_numeric($context['excerpt_end'])
                    ? (int) $context['excerpt_end']
                    : null;

                if ($relativeStart === null
                    || $relativeEnd === null
                    || $relativeStart < 0
                    || $relativeEnd > $excerptLength
                    || $relativeEnd <= $relativeStart) {
                    throw new BlueprintCandidateValidationException('A candidate context uses offsets outside the referenced excerpt.');
                }

                $evidence = mb_substr($entry->excerpt, $relativeStart, $relativeEnd - $relativeStart, 'UTF-8');

                if ($evidence === '') {
                    throw new BlueprintCandidateValidationException('A candidate context uses offsets outside the referenced excerpt.');
                }

                if (array_key_exists('evidence_text', $context)
                    && (! is_string($context['evidence_text']) || $context['evidence_text'] !== $evidence)) {
                    throw new BlueprintCandidateValidationException('A candidate evidence text does not match the referenced excerpt.');
                }

                $evidenceHash = hash('sha256', $evidence);

                if (array_key_exists('evidence_hash', $context)
                    && (! is_string($context['evidence_hash']) || $context['evidence_hash'] !== $evidenceHash)) {
                    throw new BlueprintCandidateValidationException('A candidate evidence hash does not match the referenced excerpt.');
                }

                $canonicalStart = $entry->canonicalStart + $relativeStart;
                $canonicalEnd = $entry->canonicalStart + $relativeEnd;
                $materialSlice = mb_substr($content, $canonicalStart, $canonicalEnd - $canonicalStart, 'UTF-8');

                if ($materialSlice !== $evidence) {
                    throw new BlueprintCandidateValidationException('A candidate evidence text does not match the referenced excerpt.');
                }

                if ($entry->chunk !== null
                    && ($canonicalStart < (int) $entry->chunk->char_start || $canonicalEnd > (int) $entry->chunk->char_end)) {
                    throw new BlueprintCandidateValidationException('A candidate evidence crossed a context boundary.');
                }

                if ($entry->element !== null
                    && ($canonicalStart < (int) $entry->element->char_start || $canonicalEnd > (int) $entry->element->char_end)) {
                    throw new BlueprintCandidateValidationException('A candidate evidence crossed a context boundary.');
                }

                if ($entry->element === null || $entry->chunk === null) {
                    throw new BlueprintCandidateValidationException('A candidate context is missing a required profile reference.');
                }

                $contexts[] = [
                    'profile_element_id' => (int) $entry->element->profile_element_id,
                    'profile_chunk_id' => (int) $entry->chunk->profile_chunk_id,
                    'char_start' => $canonicalStart,
                    'char_end' => $canonicalEnd,
                    'context_hash' => $evidenceHash,
                    'rank' => $index + 1,
                ];
            }

            $rows[] = [
                'objective' => $objective,
                'topic' => $topic,
                'indicator' => $indicator,
                'cognitive_level' => $cognitive,
                'difficulty' => $difficulty,
                'question_type' => QuestionType::MULTIPLE_CHOICE,
                'requested_count' => $requested,
                'origin' => BlueprintRowOrigin::Suggested,
                'contexts' => $contexts,
            ];
        }

        try {
            $this->assertShape->handle($rows);
        } catch (BlueprintRejectedException) {
            throw new BlueprintCandidateValidationException('The candidate set is not a valid simple blueprint.');
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function rejectProviderOwnedFields(array $context): void
    {
        foreach ([
            'char_start',
            'char_end',
            'profile_element_id',
            'profile_chunk_id',
            'canonical_start',
            'canonical_end',
            'fingerprint',
            'sort_order',
            'user_id',
            'material_id',
            'element_ref',
            'chunk_ref',
        ] as $forbidden) {
            if (array_key_exists($forbidden, $context)) {
                throw new BlueprintCandidateValidationException('A candidate attempted to control a server-owned field.');
            }
        }

        $elementRef = $context['element_ref'] ?? null;
        $chunkRef = $context['chunk_ref'] ?? null;

        if ($elementRef === null && $chunkRef === null && ! array_key_exists('context_ref', $context)) {
            throw new BlueprintCandidateValidationException('A candidate is missing context references.');
        }
    }

    private function requiredText(mixed $value): string
    {
        if (! is_string($value)) {
            throw new BlueprintCandidateValidationException('A candidate text field is not a string.');
        }

        $text = trim($value);

        if ($text === '') {
            throw new BlueprintCandidateValidationException('A candidate text field is empty.');
        }

        return $text;
    }
}
