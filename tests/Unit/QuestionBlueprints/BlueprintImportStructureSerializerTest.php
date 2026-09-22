<?php

declare(strict_types=1);

namespace Tests\Unit\QuestionBlueprints;

use App\Services\QuestionBlueprints\BlueprintImportStructureSerializer;
use InvalidArgumentException;
use Tests\TestCase;

class BlueprintImportStructureSerializerTest extends TestCase
{
    public function test_identical_structure_produces_identical_bytes_and_hash(): void
    {
        $serializer = new BlueprintImportStructureSerializer;
        $document = $this->tableDocument();

        $first = $serializer->serialize($document, 'blueprint-import-structure-v1');
        $second = $serializer->serialize($document, 'blueprint-import-structure-v1');

        $this->assertSame($first['json'], $second['json']);
        $this->assertSame($first['hash'], $second['hash']);
        $this->assertSame(hash('sha256', $first['json']), $first['hash']);
    }

    public function test_associative_key_order_does_not_change_output(): void
    {
        $serializer = new BlueprintImportStructureSerializer;
        $left = [
            'blocks' => [[
                'text' => 'Hello',
                'type' => 'paragraph',
                'ordinal' => 0,
            ]],
        ];
        $right = [
            'blocks' => [[
                'ordinal' => 0,
                'type' => 'paragraph',
                'text' => 'Hello',
            ]],
        ];

        $this->assertSame(
            $serializer->serialize($left, 'blueprint-import-structure-v1')['json'],
            $serializer->serialize($right, 'blueprint-import-structure-v1')['json'],
        );
    }

    public function test_preserves_order_empty_cells_and_table_properties(): void
    {
        $serializer = new BlueprintImportStructureSerializer;
        $json = $serializer->serialize($this->tableDocument(), 'blueprint-import-structure-v1')['json'];
        $decoded = json_decode($json, true);

        $this->assertSame('blueprint-import-structure-v1', $decoded['schema_version']);
        $this->assertSame('paragraph', $decoded['blocks'][0]['type']);
        $this->assertSame('table', $decoded['blocks'][1]['type']);
        $this->assertSame(0, $decoded['blocks'][1]['table_index']);
        $this->assertTrue($decoded['blocks'][1]['rows'][0]['tbl_header']);
        $this->assertFalse($decoded['blocks'][1]['rows'][1]['tbl_header']);
        $this->assertSame(['ALPHA', 'A2'], $decoded['blocks'][1]['rows'][1]['cells'][0]['paragraphs']);
        $this->assertSame([], $decoded['blocks'][1]['rows'][1]['cells'][1]['paragraphs']);
        $this->assertTrue($decoded['blocks'][1]['rows'][1]['cells'][1]['empty']);
        $this->assertSame(2, $decoded['blocks'][1]['rows'][1]['cells'][2]['grid_span']);
        $this->assertSame('continue', $decoded['blocks'][1]['rows'][1]['cells'][2]['v_merge']);
        $this->assertSame('BETA', $decoded['blocks'][1]['rows'][1]['cells'][2]['paragraphs'][0]);
    }

    public function test_does_not_reorder_semantic_arrays(): void
    {
        $serializer = new BlueprintImportStructureSerializer;
        $json = $serializer->serialize($this->tableDocument(), 'blueprint-import-structure-v1')['json'];
        $decoded = json_decode($json, true);

        $this->assertSame('Intro', $decoded['blocks'][0]['text']);
        $this->assertSame(['ALPHA', 'A2'], $decoded['blocks'][1]['rows'][1]['cells'][0]['paragraphs']);
    }

    public function test_exact_byte_bound_is_accepted_and_over_bound_is_rejected(): void
    {
        $serializer = new BlueprintImportStructureSerializer;
        $max = (int) config('question_blueprint.import_interpretation_max_request_bytes');
        $this->assertSame(262144, $max);

        $acceptedText = $this->textForSerializedSize($serializer, $max);
        $accepted = $serializer->serialize($this->paragraphDocument($acceptedText), 'blueprint-import-structure-v1');
        $this->assertSame($max, strlen($accepted['json']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(BlueprintImportStructureSerializer::ERROR_INPUT_TOO_LARGE);
        $serializer->serialize($this->paragraphDocument($acceptedText.'x'), 'blueprint-import-structure-v1');
    }

    public function test_unsupported_schema_and_missing_structure_fail_closed(): void
    {
        $serializer = new BlueprintImportStructureSerializer;

        try {
            $serializer->serialize(['blocks' => []], 'not-a-version');
            $this->fail('Unsupported schema must fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(BlueprintImportStructureSerializer::ERROR_STRUCTURE_SCHEMA_UNSUPPORTED, $exception->getMessage());
        }

        try {
            $serializer->serialize(null, 'blueprint-import-structure-v1');
            $this->fail('Missing structure must fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(BlueprintImportStructureSerializer::ERROR_STRUCTURE_MISSING, $exception->getMessage());
        }
    }

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    private function tableDocument(): array
    {
        return [
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'ordinal' => 0,
                    'text' => 'Intro',
                ],
                [
                    'type' => 'table',
                    'ordinal' => 1,
                    'table_index' => 0,
                    'rows' => [
                        [
                            'row_index' => 0,
                            'tbl_header' => true,
                            'cells' => [
                                [
                                    'cell_index' => 0,
                                    'paragraphs' => ['H1'],
                                    'grid_span' => 1,
                                    'v_merge' => 'restart',
                                    'empty' => false,
                                ],
                            ],
                        ],
                        [
                            'row_index' => 1,
                            'tbl_header' => false,
                            'cells' => [
                                [
                                    'cell_index' => 0,
                                    'paragraphs' => ['ALPHA', 'A2'],
                                    'grid_span' => 1,
                                    'v_merge' => null,
                                    'empty' => false,
                                ],
                                [
                                    'cell_index' => 1,
                                    'paragraphs' => [],
                                    'grid_span' => 1,
                                    'v_merge' => null,
                                    'empty' => true,
                                ],
                                [
                                    'cell_index' => 2,
                                    'paragraphs' => ['BETA'],
                                    'grid_span' => 2,
                                    'v_merge' => 'continue',
                                    'empty' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    private function paragraphDocument(string $text): array
    {
        return [
            'blocks' => [[
                'type' => 'paragraph',
                'ordinal' => 0,
                'text' => $text,
            ]],
        ];
    }

    private function textForSerializedSize(BlueprintImportStructureSerializer $serializer, int $targetBytes): string
    {
        $probe = $serializer->serialize($this->paragraphDocument(''), 'blueprint-import-structure-v1')['json'];
        $needed = $targetBytes - strlen($probe);

        $this->assertGreaterThan(0, $needed);

        return str_repeat('a', $needed);
    }
}
