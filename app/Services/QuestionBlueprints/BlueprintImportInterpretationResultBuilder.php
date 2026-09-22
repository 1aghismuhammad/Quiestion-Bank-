<?php

declare(strict_types=1);

namespace App\Services\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Data\QuestionBlueprints\BlueprintImportProviderInterpretation;
use App\Enums\BlueprintImportDocumentKind;
use App\Exceptions\QuestionBlueprints\BlueprintMalformedResponseException;

class BlueprintImportInterpretationResultBuilder
{
    public const SEMANTIC_KEYS = [
        'objective',
        'topic',
        'material',
        'indicator',
        'cognitive_level',
        'difficulty',
        'question_type',
        'assessment_type',
        'numbering',
        'extra',
    ];

    /**
     * @param  array<string, mixed>  $structuredDocument
     * @param  array<string, mixed>  $metadata
     */
    public function build(
        BlueprintImportProviderInterpretation $provider,
        array $structuredDocument,
        array $metadata,
    ): BlueprintImportInterpretationResult {
        $kind = BlueprintImportDocumentKind::tryFrom($provider->documentKind);

        if ($kind === null) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned an unknown document_kind.');
        }

        if (! array_is_list($provider->candidates)) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned a non-list candidates payload.');
        }

        $maxCandidates = max(1, (int) config('question_blueprint.max_import_candidates', 100));

        if (count($provider->candidates) > $maxCandidates) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned too many candidates.');
        }

        if (
            ($kind === BlueprintImportDocumentKind::TaxonomyNonBlueprint || $kind === BlueprintImportDocumentKind::Empty)
            && $provider->candidates !== []
        ) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned candidates for a zero-candidate document kind.');
        }

        $blocks = $structuredDocument['blocks'] ?? null;

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw new BlueprintMalformedResponseException('The interpretation structure is missing blocks.');
        }

        $warnings = $this->stringList($provider->warnings);
        $candidates = [];

        foreach ($provider->candidates as $candidate) {
            if (! is_array($candidate)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned a non-object candidate.');
            }

            $candidates[] = $this->buildCandidate($candidate, $blocks);
        }

        return new BlueprintImportInterpretationResult($kind, $candidates, $warnings, $metadata);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function buildCandidate(array $candidate, array $blocks): array
    {
        foreach (['bindings', 'warnings', 'unresolved'] as $key) {
            if (! array_key_exists($key, $candidate)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned a candidate missing '.$key.'.');
            }
        }

        $bindings = $candidate['bindings'];

        if (! is_array($bindings)) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned invalid candidate bindings.');
        }

        foreach (array_keys($bindings) as $key) {
            if (! is_string($key) || ! in_array($key, self::SEMANTIC_KEYS, true)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned an unknown semantic key.');
            }
        }

        $raw = [];
        $sourceRefs = [];
        $unresolved = [];
        $refCount = 0;

        foreach (self::SEMANTIC_KEYS as $key) {
            $refs = $bindings[$key] ?? [];

            if (! is_array($refs) || ! array_is_list($refs)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned invalid source refs.');
            }

            $resolved = $this->resolveField($refs, $blocks);
            $raw['raw_'.$key] = $resolved['text'];
            $sourceRefs[$key] = $resolved['refs'];
            $refCount += count($resolved['refs']);

            if ($resolved['text'] === null || $resolved['text'] === '') {
                $unresolved[] = $key;
            }
        }

        if ($refCount === 0) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned a candidate without source provenance.');
        }

        $providerUnresolved = $this->stringList($candidate['unresolved']);

        foreach ($providerUnresolved as $marker) {
            if (! in_array($marker, self::SEMANTIC_KEYS, true)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned an unknown unresolved marker.');
            }
        }

        $unresolved = array_values(array_unique([...$unresolved, ...$providerUnresolved]));

        return [
            ...$raw,
            'source_refs' => $sourceRefs,
            'warnings' => $this->stringList($candidate['warnings']),
            'unresolved' => $unresolved,
            'cognitive_level' => null,
            'difficulty' => null,
            'question_type' => null,
            'assessment_type' => null,
            'requested_count' => null,
        ];
    }

    /**
     * @param  list<mixed>  $refs
     * @param  list<array<string, mixed>>  $blocks
     * @return array{text: ?string, refs: list<array<string, mixed>>}
     */
    private function resolveField(array $refs, array $blocks): array
    {
        if ($refs === []) {
            return ['text' => null, 'refs' => []];
        }

        $normalized = [];
        $segments = [];
        $seen = [];

        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned a non-object source ref.');
            }

            $canonical = $this->canonicalizeRef($ref, $blocks);
            $fingerprint = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (! is_string($fingerprint) || isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $normalized[] = $canonical;
        }

        usort($normalized, $this->compareRefs(...));

        foreach ($normalized as $ref) {
            $segments[] = $this->textForRef($ref, $blocks);
        }

        $text = implode("\n", $segments);

        return [
            'text' => $text,
            'refs' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function canonicalizeRef(array $ref, array $blocks): array
    {
        $kind = $ref['kind'] ?? null;
        $ordinal = $ref['block_ordinal'] ?? null;

        if ($kind !== 'paragraph' && $kind !== 'cell') {
            throw new BlueprintMalformedResponseException('The interpretation provider returned an unknown source-ref kind.');
        }

        if (! is_int($ordinal) && ! (is_string($ordinal) && ctype_digit($ordinal))) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned an invalid block_ordinal.');
        }

        $ordinal = (int) $ordinal;
        $block = $this->blockByOrdinal($blocks, $ordinal);

        if ($kind === 'paragraph') {
            if (($block['type'] ?? null) !== 'paragraph') {
                throw new BlueprintMalformedResponseException('The interpretation provider referenced a non-paragraph block.');
            }

            $canonical = [
                'kind' => 'paragraph',
                'block_ordinal' => $ordinal,
            ];

            $this->assignRole($canonical, $ref, ['paragraph']);

            return $canonical;
        }

        if (($block['type'] ?? null) !== 'table') {
            throw new BlueprintMalformedResponseException('The interpretation provider referenced a non-table block.');
        }

        $tableIndex = $this->requireInt($ref['table_index'] ?? null, 'table_index');
        $rowIndex = $this->requireInt($ref['row_index'] ?? null, 'row_index');
        $cellIndex = $this->requireInt($ref['cell_index'] ?? null, 'cell_index');

        if ((int) ($block['table_index'] ?? -1) !== $tableIndex) {
            throw new BlueprintMalformedResponseException('The interpretation provider referenced a mismatched table_index.');
        }

        $cell = $this->cellAt($block, $rowIndex, $cellIndex);
        $paragraphIndexes = $this->paragraphIndexes($ref['paragraph_indexes'] ?? null, $cell);

        $canonical = [
            'kind' => 'cell',
            'block_ordinal' => $ordinal,
            'table_index' => $tableIndex,
            'row_index' => $rowIndex,
            'cell_index' => $cellIndex,
            'paragraph_indexes' => $paragraphIndexes,
        ];

        $this->assignRole($canonical, $ref, ['cell', 'row_header', 'column_header', 'intersection']);

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @param  array<string, mixed>  $ref
     * @param  list<string>  $allowed
     */
    private function assignRole(array &$canonical, array $ref, array $allowed): void
    {
        if (! array_key_exists('role', $ref)) {
            return;
        }

        $role = $ref['role'];

        if (! is_string($role) || ! in_array($role, $allowed, true)) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned an invalid source-ref role.');
        }

        $canonical['role'] = $role;
    }

    /**
     * @param  array<string, mixed>  $ref
     * @param  list<array<string, mixed>>  $blocks
     */
    private function textForRef(array $ref, array $blocks): string
    {
        $block = $this->blockByOrdinal($blocks, (int) $ref['block_ordinal']);

        if ($ref['kind'] === 'paragraph') {
            return (string) ($block['text'] ?? '');
        }

        $cell = $this->cellAt($block, (int) $ref['row_index'], (int) $ref['cell_index']);
        $paragraphs = [];

        foreach ($ref['paragraph_indexes'] as $index) {
            $paragraphs[] = (string) $cell['paragraphs'][$index];
        }

        return implode("\n", $paragraphs);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function blockByOrdinal(array $blocks, int $ordinal): array
    {
        foreach ($blocks as $block) {
            if (is_array($block) && (int) ($block['ordinal'] ?? -1) === $ordinal) {
                return $block;
            }
        }

        throw new BlueprintMalformedResponseException('The interpretation provider referenced an unknown block.');
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function cellAt(array $block, int $rowIndex, int $cellIndex): array
    {
        $rows = $block['rows'] ?? null;

        if (! is_array($rows)) {
            throw new BlueprintMalformedResponseException('The interpretation provider referenced a table without rows.');
        }

        foreach ($rows as $row) {
            if (! is_array($row) || (int) ($row['row_index'] ?? -1) !== $rowIndex) {
                continue;
            }

            $cells = $row['cells'] ?? null;

            if (! is_array($cells)) {
                throw new BlueprintMalformedResponseException('The interpretation provider referenced a row without cells.');
            }

            foreach ($cells as $cell) {
                if (is_array($cell) && (int) ($cell['cell_index'] ?? -1) === $cellIndex) {
                    return $cell;
                }
            }

            throw new BlueprintMalformedResponseException('The interpretation provider referenced an unknown cell_index.');
        }

        throw new BlueprintMalformedResponseException('The interpretation provider referenced an unknown row_index.');
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return list<int>
     */
    private function paragraphIndexes(mixed $indexes, array $cell): array
    {
        $paragraphs = $cell['paragraphs'] ?? null;

        if (! is_array($paragraphs) || ! array_is_list($paragraphs)) {
            throw new BlueprintMalformedResponseException('The interpretation provider referenced a cell without paragraphs.');
        }

        if ($indexes === null) {
            $all = [];

            for ($i = 0; $i < count($paragraphs); $i++) {
                $all[] = $i;
            }

            return $all;
        }

        if (! is_array($indexes) || ! array_is_list($indexes)) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned invalid paragraph_indexes.');
        }

        $resolved = [];

        foreach ($indexes as $index) {
            $value = $this->requireInt($index, 'paragraph_indexes');

            if (! array_key_exists($value, $paragraphs)) {
                throw new BlueprintMalformedResponseException('The interpretation provider referenced an unknown paragraph index.');
            }

            $resolved[$value] = $value;
        }

        ksort($resolved, SORT_NUMERIC);

        return array_values($resolved);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareRefs(array $left, array $right): int
    {
        foreach (['block_ordinal', 'row_index', 'cell_index'] as $key) {
            $a = (int) ($left[$key] ?? -1);
            $b = (int) ($right[$key] ?? -1);

            if ($a !== $b) {
                return $a <=> $b;
            }
        }

        $leftParagraph = (int) (($left['paragraph_indexes'][0] ?? -1));
        $rightParagraph = (int) (($right['paragraph_indexes'][0] ?? -1));

        return $leftParagraph <=> $rightParagraph;
    }

    private function requireInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new BlueprintMalformedResponseException('The interpretation provider returned an invalid '.$field.'.');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new BlueprintMalformedResponseException('The interpretation provider returned invalid string lists.');
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned a non-string warning.');
            }

            $items[] = $item;
        }

        return array_values($items);
    }
}
