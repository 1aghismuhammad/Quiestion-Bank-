<?php

declare(strict_types=1);

namespace App\Services\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportProviderInterpretation;
use App\Enums\BlueprintImportDocumentKind;

/**
 * Binds known kisi-kisi header cells to interpretation source refs.
 *
 * A table is a blueprint grid only when one row maps to at least
 * {@see self::RECOGNITION_MIN_DISTINCT_FIELDS} distinct schema fields
 * using the exact header dictionary. Generic "No" does not count toward
 * that threshold and never overrides "No Soal".
 */
final class DeterministicBlueprintTableBinder
{
    public const RECOGNITION_MIN_DISTINCT_FIELDS = 4;

    /**
     * @var array<string, string>
     */
    private const HEADER_FIELDS = [
        'tujuan pembelajaran' => 'objective',
        'kompetensi tujuan' => 'objective',
        'elemen' => 'topic',
        'topik' => 'topic',
        'materi' => 'material',
        'indikator' => 'indicator',
        'indikator soal' => 'indicator',
        'level kognitif' => 'cognitive_level',
        'bentuk soal' => 'question_type',
        'btk' => 'question_type',
        'tipe soal' => 'question_type',
        'tingkat kesulitan' => 'difficulty',
        'kesulitan' => 'difficulty',
        'jenis asesmen' => 'assessment_type',
        'no soal' => 'numbering',
        'nomor soal' => 'numbering',
    ];

    /**
     * @var list<string>
     */
    private const GENERIC_NUMBER_HEADERS = [
        'no',
    ];

    /**
     * @var list<string>
     */
    private const NOTE_HEADERS = [
        'ket',
        'keterangan',
        'catatan',
    ];

    /**
     * @var list<string>
     */
    private const ASSESSMENT_VALUES = [
        'formatif',
        'sumatif',
        'diagnostik',
    ];

    /**
     * @param  array<string, mixed>  $structure
     */
    public function merge(
        BlueprintImportProviderInterpretation $provider,
        array $structure,
    ): BlueprintImportProviderInterpretation {
        $recognized = $this->recognize($structure);

        if ($recognized['candidates'] === []) {
            return $provider;
        }

        $candidates = $recognized['candidates'];

        foreach ($provider->candidates as $providerCandidate) {
            if (! is_array($providerCandidate)) {
                continue;
            }

            $bindings = is_array($providerCandidate['bindings'] ?? null)
                ? $providerCandidate['bindings']
                : [];
            $rowKeys = $this->rowKeysInBindings($bindings, $recognized['row_keys']);

            if (count($rowKeys) > 1) {
                $extra = $this->withoutRecognizedRows($providerCandidate, $recognized['row_keys'], $recognized['header_keys']);

                if ($extra !== null) {
                    $candidates[] = $extra;
                }

                continue;
            }

            if (count($rowKeys) === 1) {
                $key = $rowKeys[0];
                $candidates[$key]['bindings'] = $this->fillMissing(
                    $candidates[$key]['bindings'],
                    $bindings,
                    $key,
                    $recognized['row_keys'],
                );
                $candidates[$key]['warnings'] = $this->mergeWarnings(
                    $candidates[$key]['warnings'],
                    $providerCandidate['warnings'] ?? [],
                );

                continue;
            }

            $extra = $this->withoutHeaderRefs($providerCandidate, $recognized['header_keys']);

            if ($extra !== null) {
                $candidates[] = $extra;
            }
        }

        return new BlueprintImportProviderInterpretation(
            BlueprintImportDocumentKind::BlueprintLike->value,
            array_values($candidates),
            is_array($provider->warnings) ? $provider->warnings : [],
            $provider->metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array{
     *     candidates: array<string, array{bindings: array<string, list<array<string, mixed>>>, warnings: list<string>, unresolved: list<string>}>,
     *     row_keys: array<string, true>,
     *     header_keys: array<string, true>
     * }
     */
    private function recognize(array $structure): array
    {
        $candidates = [];
        $rowKeys = [];
        $headerKeys = [];
        $blocks = $structure['blocks'] ?? null;

        if (! is_array($blocks)) {
            return ['candidates' => [], 'row_keys' => [], 'header_keys' => []];
        }

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'table') {
                continue;
            }

            $rows = $block['rows'] ?? null;

            if (! is_array($rows)) {
                continue;
            }

            $header = null;
            $headerRowIndex = null;

            foreach ($this->sortedRows($rows) as $row) {
                $mapped = $this->mapHeader($row);
                $recognizedFields = count($mapped['fields']) - ($mapped['numbering_from_generic'] ? 1 : 0);

                if ($recognizedFields < self::RECOGNITION_MIN_DISTINCT_FIELDS) {
                    continue;
                }

                $header = $mapped;
                $headerRowIndex = (int) ($row['row_index'] ?? -1);
                $headerKeys[$this->key((int) ($block['table_index'] ?? -1), $headerRowIndex)] = true;
                break;
            }

            if ($header === null || $headerRowIndex === null) {
                continue;
            }

            $tableIndex = (int) ($block['table_index'] ?? -1);
            $ordinal = (int) ($block['ordinal'] ?? -1);

            foreach ($this->sortedRows($rows) as $row) {
                $rowIndex = (int) ($row['row_index'] ?? -1);

                if ($rowIndex === $headerRowIndex || ! $this->rowHasText($row)) {
                    continue;
                }

                $bindings = $this->rowBindings($block, $row, $header, $ordinal, $tableIndex, $rowIndex);

                if ($bindings === []) {
                    continue;
                }

                $key = $this->key($tableIndex, $rowIndex);
                $rowKeys[$key] = true;
                $candidates[$key] = [
                    'bindings' => $bindings,
                    'warnings' => [],
                    'unresolved' => [],
                ];
            }
        }

        return [
            'candidates' => $candidates,
            'row_keys' => $rowKeys,
            'header_keys' => $headerKeys,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{fields: array<string, int>, generic_number: ?int, note: ?int, numbering_from_generic: bool}
     */
    private function mapHeader(array $row): array
    {
        $fields = [];
        $genericNumber = null;
        $note = null;

        foreach ($this->sortedCells($row['cells'] ?? []) as $cell) {
            $label = $this->normalize($this->cellText($cell));
            $cellIndex = (int) ($cell['cell_index'] ?? -1);

            if ($label === '' || $cellIndex < 0) {
                continue;
            }

            if (isset(self::HEADER_FIELDS[$label]) && ! isset($fields[self::HEADER_FIELDS[$label]])) {
                $fields[self::HEADER_FIELDS[$label]] = $cellIndex;
            }

            if (in_array($label, self::GENERIC_NUMBER_HEADERS, true) && $genericNumber === null) {
                $genericNumber = $cellIndex;
            }

            if (in_array($label, self::NOTE_HEADERS, true) && $note === null) {
                $note = $cellIndex;
            }
        }

        $numberingFromGeneric = false;

        if (! isset($fields['numbering']) && $genericNumber !== null) {
            $fields['numbering'] = $genericNumber;
            $numberingFromGeneric = true;
        }

        return [
            'fields' => $fields,
            'generic_number' => $genericNumber,
            'note' => $note,
            'numbering_from_generic' => $numberingFromGeneric,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $row
     * @param  array{fields: array<string, int>, generic_number: ?int, note: ?int, numbering_from_generic: bool}  $header
     * @return array<string, list<array<string, mixed>>>
     */
    private function rowBindings(
        array $block,
        array $row,
        array $header,
        int $ordinal,
        int $tableIndex,
        int $rowIndex,
    ): array {
        $bindings = [];
        $cells = $this->cellsByIndex($row['cells'] ?? []);

        foreach ($header['fields'] as $field => $cellIndex) {
            if (! isset($cells[$cellIndex]) || $this->cellText($cells[$cellIndex]) === '') {
                continue;
            }

            $bindings[$field] = [$this->cellRef($ordinal, $tableIndex, $rowIndex, $cellIndex)];
        }

        if ($header['note'] !== null && isset($cells[$header['note']])) {
            $note = $this->normalize($this->cellText($cells[$header['note']]));

            if (in_array($note, self::ASSESSMENT_VALUES, true)) {
                $bindings['assessment_type'] = [$this->cellRef($ordinal, $tableIndex, $rowIndex, $header['note'])];
            }
        }

        return $bindings;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $deterministic
     * @param  array<string, mixed>  $providerBindings
     * @param  array<string, true>  $rowKeys
     * @return array<string, list<array<string, mixed>>>
     */
    private function fillMissing(array $deterministic, array $providerBindings, string $rowKey, array $rowKeys): array
    {
        foreach ($providerBindings as $field => $refs) {
            if (! is_string($field) || isset($deterministic[$field]) || ! is_array($refs) || $refs === []) {
                continue;
            }

            $safe = [];

            foreach ($refs as $ref) {
                if (! is_array($ref)) {
                    continue;
                }

                $key = $this->refRowKey($ref);

                if ($key !== null && $key !== $rowKey && isset($rowKeys[$key])) {
                    continue;
                }

                $safe[] = $ref;
            }

            if ($safe !== []) {
                $deterministic[$field] = $safe;
            }
        }

        return $deterministic;
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @param  array<string, true>  $rowKeys
     * @return list<string>
     */
    private function rowKeysInBindings(array $bindings, array $rowKeys): array
    {
        $found = [];

        foreach ($bindings as $refs) {
            if (! is_array($refs)) {
                continue;
            }

            foreach ($refs as $ref) {
                if (! is_array($ref)) {
                    continue;
                }

                $key = $this->refRowKey($ref);

                if ($key !== null && isset($rowKeys[$key])) {
                    $found[$key] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, true>  $rowKeys
     * @param  array<string, true>  $headerKeys
     * @return array{bindings: array<string, list<array<string, mixed>>>, warnings: list<string>, unresolved: list<string>}|null
     */
    private function withoutRecognizedRows(array $candidate, array $rowKeys, array $headerKeys): ?array
    {
        return $this->filterCandidate($candidate, function (array $ref) use ($rowKeys, $headerKeys): bool {
            $key = $this->refRowKey($ref);

            return $key === null || (! isset($rowKeys[$key]) && ! isset($headerKeys[$key]));
        });
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, true>  $headerKeys
     * @return array{bindings: array<string, list<array<string, mixed>>>, warnings: list<string>, unresolved: list<string>}|null
     */
    private function withoutHeaderRefs(array $candidate, array $headerKeys): ?array
    {
        return $this->filterCandidate($candidate, function (array $ref) use ($headerKeys): bool {
            $key = $this->refRowKey($ref);

            return $key === null || ! isset($headerKeys[$key]);
        });
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  callable(array<string, mixed>): bool  $keep
     * @return array{bindings: array<string, list<array<string, mixed>>>, warnings: list<string>, unresolved: list<string>}|null
     */
    private function filterCandidate(array $candidate, callable $keep): ?array
    {
        $bindings = is_array($candidate['bindings'] ?? null) ? $candidate['bindings'] : [];
        $filtered = [];

        foreach ($bindings as $field => $refs) {
            if (! is_string($field) || ! is_array($refs)) {
                continue;
            }

            $safe = [];

            foreach ($refs as $ref) {
                if (is_array($ref) && $keep($ref)) {
                    $safe[] = $ref;
                }
            }

            if ($safe !== []) {
                $filtered[$field] = $safe;
            }
        }

        if ($filtered === []) {
            return null;
        }

        return [
            'bindings' => $filtered,
            'warnings' => is_array($candidate['warnings'] ?? null) ? array_values(array_filter($candidate['warnings'], 'is_string')) : [],
            'unresolved' => is_array($candidate['unresolved'] ?? null) ? array_values(array_filter($candidate['unresolved'], 'is_string')) : [],
        ];
    }

    /**
     * @param  list<string>  $current
     * @return list<string>
     */
    private function mergeWarnings(array $current, mixed $extra): array
    {
        if (! is_array($extra)) {
            return $current;
        }

        foreach ($extra as $warning) {
            if (is_string($warning) && ! in_array($warning, $current, true)) {
                $current[] = $warning;
            }
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function refRowKey(array $ref): ?string
    {
        if (($ref['kind'] ?? null) !== 'cell' || ! isset($ref['table_index'], $ref['row_index'])) {
            return null;
        }

        if (! is_numeric($ref['table_index']) || ! is_numeric($ref['row_index'])) {
            return null;
        }

        return $this->key((int) $ref['table_index'], (int) $ref['row_index']);
    }

    /**
     * @param  array<string, mixed>  $cell
     */
    private function cellRef(int $ordinal, int $tableIndex, int $rowIndex, int $cellIndex): array
    {
        return [
            'kind' => 'cell',
            'block_ordinal' => $ordinal,
            'table_index' => $tableIndex,
            'row_index' => $rowIndex,
            'cell_index' => $cellIndex,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortedRows(array $rows): array
    {
        $sorted = array_values(array_filter($rows, 'is_array'));
        usort($sorted, static fn (array $left, array $right): int => ((int) ($left['row_index'] ?? 0)) <=> ((int) ($right['row_index'] ?? 0)));

        return $sorted;
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @return list<array<string, mixed>>
     */
    private function sortedCells(array $cells): array
    {
        $sorted = array_values(array_filter($cells, 'is_array'));
        usort($sorted, static fn (array $left, array $right): int => ((int) ($left['cell_index'] ?? 0)) <=> ((int) ($right['cell_index'] ?? 0)));

        return $sorted;
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @return array<int, array<string, mixed>>
     */
    private function cellsByIndex(array $cells): array
    {
        $indexed = [];

        foreach ($this->sortedCells($cells) as $cell) {
            $indexed[(int) ($cell['cell_index'] ?? -1)] = $cell;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowHasText(array $row): bool
    {
        foreach ($row['cells'] ?? [] as $cell) {
            if (is_array($cell) && $this->cellText($cell) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $cell
     */
    private function cellText(array $cell): string
    {
        $parts = [];

        foreach ($cell['paragraphs'] ?? [] as $paragraph) {
            if (is_string($paragraph) && trim($paragraph) !== '') {
                $parts[] = trim($paragraph);
            }
        }

        return trim(implode(' ', $parts));
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['/', '\\', '_', '-'], ' ', $value);
        $value = preg_replace('/[.,;:!?()]+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value), 'UTF-8');
    }

    private function key(int $tableIndex, int $rowIndex): string
    {
        return $tableIndex.':'.$rowIndex;
    }
}
