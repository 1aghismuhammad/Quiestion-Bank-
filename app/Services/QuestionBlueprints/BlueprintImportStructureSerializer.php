<?php

declare(strict_types=1);

namespace App\Services\QuestionBlueprints;

use App\Data\QuestionBlueprints\ImportStructuredDocument;
use InvalidArgumentException;
use JsonException;

class BlueprintImportStructureSerializer
{
    public const ERROR_STRUCTURE_MISSING = 'structure_missing';

    public const ERROR_STRUCTURE_SCHEMA_UNSUPPORTED = 'structure_schema_unsupported';

    public const ERROR_INPUT_TOO_LARGE = 'input_too_large';

    /**
     * @param  array<string, mixed>|null  $structuredDocument
     * @return array{json: string, hash: string}
     */
    public function serialize(?array $structuredDocument, ?string $schemaVersion): array
    {
        $expected = (string) config(
            'question_blueprint.import_structure_schema_version',
            ImportStructuredDocument::SCHEMA_VERSION,
        );

        if (! is_string($schemaVersion) || $schemaVersion !== $expected) {
            throw new InvalidArgumentException(self::ERROR_STRUCTURE_SCHEMA_UNSUPPORTED);
        }

        if ($structuredDocument === null || ! array_key_exists('blocks', $structuredDocument)) {
            throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
        }

        $blocks = $structuredDocument['blocks'];

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
        }

        $canonicalBlocks = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
            }

            $canonicalBlocks[] = $this->canonicalizeBlock($block);
        }

        $payload = $this->canonicalizeAssociative([
            'schema_version' => $schemaVersion,
            'blocks' => $canonicalBlocks,
        ]);

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
        }

        $maxBytes = max(1, (int) config('question_blueprint.import_interpretation_max_request_bytes', 262_144));

        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException(self::ERROR_INPUT_TOO_LARGE);
        }

        return [
            'json' => $json,
            'hash' => hash('sha256', $json),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function canonicalizeBlock(array $block): array
    {
        $type = $block['type'] ?? null;

        if ($type === 'paragraph') {
            if (! array_key_exists('ordinal', $block) || ! array_key_exists('text', $block)) {
                throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
            }

            return $this->canonicalizeAssociative([
                'type' => 'paragraph',
                'ordinal' => $block['ordinal'],
                'text' => is_string($block['text']) ? $block['text'] : (string) $block['text'],
            ]);
        }

        if ($type === 'table') {
            return $this->canonicalizeTable($block);
        }

        throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function canonicalizeTable(array $block): array
    {
        $rows = $block['rows'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
        }

        $canonicalRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
            }

            $cells = $row['cells'] ?? null;

            if (! is_array($cells) || ! array_is_list($cells)) {
                throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
            }

            $canonicalCells = [];

            foreach ($cells as $cell) {
                if (! is_array($cell)) {
                    throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
                }

                $paragraphs = $cell['paragraphs'] ?? null;

                if (! is_array($paragraphs) || ! array_is_list($paragraphs)) {
                    throw new InvalidArgumentException(self::ERROR_STRUCTURE_MISSING);
                }

                $canonicalParagraphs = [];

                foreach ($paragraphs as $paragraph) {
                    $canonicalParagraphs[] = is_string($paragraph) ? $paragraph : (string) $paragraph;
                }

                $canonicalCells[] = $this->canonicalizeAssociative([
                    'cell_index' => $cell['cell_index'] ?? null,
                    'paragraphs' => $canonicalParagraphs,
                    'grid_span' => $cell['grid_span'] ?? 1,
                    'v_merge' => $cell['v_merge'] ?? null,
                    'empty' => ($cell['empty'] ?? false) === true,
                ]);
            }

            $canonicalRows[] = $this->canonicalizeAssociative([
                'row_index' => $row['row_index'] ?? null,
                'tbl_header' => ($row['tbl_header'] ?? false) === true,
                'cells' => $canonicalCells,
            ]);
        }

        return $this->canonicalizeAssociative([
            'type' => 'table',
            'ordinal' => $block['ordinal'] ?? null,
            'table_index' => $block['table_index'] ?? null,
            'rows' => $canonicalRows,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function canonicalizeAssociative(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item) && ! array_is_list($item)) {
                $value[$key] = $this->canonicalizeAssociative($item);
            }
        }

        return $value;
    }
}
