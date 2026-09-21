<?php

declare(strict_types=1);

namespace Tests\Unit\QuestionBlueprints;

use App\Data\QuestionBlueprints\ImportStructuredDocument;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Services\QuestionBlueprints\BlueprintImportDocxStructureExtractor;
use Tests\Support\Materials\MaterialExtractionFixtures;
use Tests\Support\QuestionBlueprints\BlueprintImportDocxFixtures;
use Tests\TestCase;

class BlueprintImportDocxStructureExtractorTest extends TestCase
{
    public function test_body_paragraphs_are_ordered_paragraph_blocks(): void
    {
        $document = $this->extract(BlueprintImportDocxFixtures::sequentialBodyParagraphs());

        $this->assertSame([
            [
                'type' => 'paragraph',
                'ordinal' => 0,
                'text' => 'ALPHA',
            ],
            [
                'type' => 'paragraph',
                'ordinal' => 1,
                'text' => 'BETA',
            ],
        ], $document->blocks);
    }

    public function test_adjacent_table_cells_are_distinct_from_body_paragraphs(): void
    {
        $cells = $this->extract(BlueprintImportDocxFixtures::adjacentTableCells())->toArray();
        $paragraphs = $this->extract(BlueprintImportDocxFixtures::sequentialBodyParagraphs())->toArray();

        $this->assertNotSame($paragraphs, $cells);
        $this->assertSame('table', $cells['blocks'][0]['type']);
        $this->assertSame('paragraph', $paragraphs['blocks'][0]['type']);
        $this->assertSame(['ALPHA'], $cells['blocks'][0]['rows'][0]['cells'][0]['paragraphs']);
        $this->assertSame(['BETA'], $cells['blocks'][0]['rows'][0]['cells'][1]['paragraphs']);
        $this->assertSame(0, $cells['blocks'][0]['rows'][0]['cells'][0]['cell_index']);
        $this->assertSame(1, $cells['blocks'][0]['rows'][0]['cells'][1]['cell_index']);
    }

    public function test_multi_paragraph_cell_is_distinct_from_adjacent_cells(): void
    {
        $multi = $this->extract(BlueprintImportDocxFixtures::multiParagraphSingleCell())->toArray();
        $adjacent = $this->extract(BlueprintImportDocxFixtures::multipleAdjacentCells())->toArray();

        $this->assertNotSame($adjacent, $multi);
        $this->assertCount(1, $multi['blocks'][0]['rows'][0]['cells']);
        $this->assertSame(['LINE1', 'LINE2'], $multi['blocks'][0]['rows'][0]['cells'][0]['paragraphs']);
        $this->assertCount(2, $adjacent['blocks'][0]['rows'][0]['cells']);
        $this->assertSame(['LINE1'], $adjacent['blocks'][0]['rows'][0]['cells'][0]['paragraphs']);
        $this->assertSame(['LINE2'], $adjacent['blocks'][0]['rows'][0]['cells'][1]['paragraphs']);
    }

    public function test_metadata_paragraphs_are_distinct_from_equivalent_table(): void
    {
        $paragraphs = $this->extract(BlueprintImportDocxFixtures::metadataParagraphsOnly())->toArray();
        $table = $this->extract(BlueprintImportDocxFixtures::equivalentFlattenedTableContent())->toArray();

        $this->assertNotSame($table, $paragraphs);
        $this->assertSame('paragraph', $paragraphs['blocks'][0]['type']);
        $this->assertSame('table', $table['blocks'][0]['type']);
        $this->assertCount(4, $paragraphs['blocks']);
        $this->assertCount(1, $table['blocks']);
        $this->assertCount(4, $table['blocks'][0]['rows']);
    }

    public function test_unique_token_2x3_table_preserves_row_and_cell_indices(): void
    {
        $table = $this->extract(BlueprintImportDocxFixtures::uniqueTokenTable2x3())->blocks[0];

        $this->assertSame('table', $table['type']);
        $this->assertSame(0, $table['table_index']);
        $this->assertSame(0, $table['ordinal']);
        $this->assertSame('R0C0', $table['rows'][0]['cells'][0]['paragraphs'][0]);
        $this->assertSame('R0C2', $table['rows'][0]['cells'][2]['paragraphs'][0]);
        $this->assertSame('R1C1', $table['rows'][1]['cells'][1]['paragraphs'][0]);
        $this->assertSame(1, $table['rows'][1]['row_index']);
        $this->assertSame(2, $table['rows'][1]['cells'][2]['cell_index']);
    }

    public function test_case_a_preserves_extra_durasi_column(): void
    {
        $cells = $this->extract(BlueprintImportDocxFixtures::caseADirectRowBlueprint())->blocks[0]['rows'][0]['cells'];

        $this->assertCount(7, $cells);
        $this->assertSame(['Durasi Tes'], $cells[1]['paragraphs']);
        $this->assertSame(['90 menit'], $this->extract(BlueprintImportDocxFixtures::caseADirectRowBlueprint())->blocks[0]['rows'][1]['cells'][1]['paragraphs']);
    }

    public function test_metadata_then_table_preserves_block_order_and_types(): void
    {
        $blocks = $this->extract(BlueprintImportDocxFixtures::caseCMetadataThenTable())->blocks;

        $this->assertCount(5, $blocks);
        $this->assertSame('paragraph', $blocks[0]['type']);
        $this->assertSame('Jenis Sekolah: SMP', $blocks[0]['text']);
        $this->assertSame(0, $blocks[0]['ordinal']);
        $this->assertSame('table', $blocks[4]['type']);
        $this->assertSame(4, $blocks[4]['ordinal']);
        $this->assertSame(0, $blocks[4]['table_index']);
        $this->assertSame(['Menentukan gagasan utama'], $blocks[4]['rows'][1]['cells'][1]['paragraphs']);
    }

    public function test_empty_middle_cell_remains_present(): void
    {
        $cells = $this->extract(BlueprintImportDocxFixtures::emptyMiddleCell())->blocks[0]['rows'][0]['cells'];

        $this->assertCount(3, $cells);
        $this->assertSame(['LEFT'], $cells[0]['paragraphs']);
        $this->assertTrue($cells[1]['empty']);
        $this->assertSame(['RIGHT'], $cells[2]['paragraphs']);
        $this->assertFalse($cells[0]['empty']);
        $this->assertSame(1, $cells[1]['cell_index']);
    }

    public function test_absent_middle_cell_has_two_cells(): void
    {
        $cells = $this->extract(BlueprintImportDocxFixtures::absentMiddleCell())->blocks[0]['rows'][0]['cells'];

        $this->assertCount(2, $cells);
        $this->assertSame(['LEFT'], $cells[0]['paragraphs']);
        $this->assertSame(['RIGHT'], $cells[1]['paragraphs']);
    }

    public function test_grid_span_is_preserved(): void
    {
        $row = $this->extract(BlueprintImportDocxFixtures::horizontalGridSpan())->blocks[0]['rows'][0];

        $this->assertSame(2, $row['cells'][0]['grid_span']);
        $this->assertSame(['SPAN_AB'], $row['cells'][0]['paragraphs']);
        $this->assertSame(1, $row['cells'][1]['grid_span']);
        $this->assertSame(['SIDE_C'], $row['cells'][1]['paragraphs']);
    }

    public function test_vmerge_restart_and_continue_are_preserved(): void
    {
        $rows = $this->extract(BlueprintImportDocxFixtures::caseDVerticalMerge())->blocks[0]['rows'];

        $this->assertSame('restart', $rows[0]['cells'][0]['v_merge']);
        $this->assertFalse($rows[0]['cells'][0]['empty']);
        $this->assertSame('continue', $rows[1]['cells'][0]['v_merge']);
        $this->assertTrue($rows[1]['cells'][0]['empty']);
        $this->assertSame('continue', $rows[2]['cells'][0]['v_merge']);
        $this->assertNull($rows[0]['cells'][1]['v_merge']);
        $this->assertSame(['PG 1'], $rows[0]['cells'][2]['paragraphs']);
        $this->assertSame(['Esai 1'], $rows[2]['cells'][2]['paragraphs']);
    }

    public function test_tbl_header_property_is_preserved(): void
    {
        $withHeader = $this->extract(BlueprintImportDocxFixtures::syntheticTblHeaderSemantics())->blocks[0]['rows'];
        $withoutHeader = $this->extract(BlueprintImportDocxFixtures::syntheticTblHeaderWithoutProperty())->blocks[0]['rows'];

        $this->assertTrue($withHeader[0]['tbl_header']);
        $this->assertFalse($withHeader[1]['tbl_header']);
        $this->assertFalse($withoutHeader[0]['tbl_header']);
        $this->assertNotSame($withoutHeader, $withHeader);
    }

    public function test_tbl_header_absent_is_false(): void
    {
        $this->assertFalse($this->extract($this->headerRowTable(null))->blocks[0]['rows'][0]['tbl_header']);
    }

    public function test_tbl_header_present_without_val_is_true(): void
    {
        $this->assertTrue($this->extract($this->headerRowTable(''))->blocks[0]['rows'][0]['tbl_header']);
    }

    public function test_tbl_header_on_values_are_true(): void
    {
        foreach (['1', 'true', 'TRUE', 'on', 'On'] as $value) {
            $this->assertTrue(
                $this->extract($this->headerRowTable($value))->blocks[0]['rows'][0]['tbl_header'],
                $value,
            );
        }
    }

    public function test_tbl_header_off_values_are_false(): void
    {
        foreach (['0', 'false', 'FALSE', 'off', 'Off'] as $value) {
            $this->assertFalse(
                $this->extract($this->headerRowTable($value))->blocks[0]['rows'][0]['tbl_header'],
                $value,
            );
        }
    }

    public function test_tbl_header_invalid_value_fails_closed(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract($this->headerRowTable('maybe'));
    }

    public function test_truncated_xml_after_complete_paragraph_fails_closed(): void
    {
        $complete = MaterialExtractionFixtures::documentXml('<w:p><w:r><w:t>Hello</w:t></w:r></w:p>');
        $truncated = substr($complete, 0, -strlen('</w:body></w:document>'));

        $this->assertStringContainsString('Hello', $truncated);
        $this->assertStringNotContainsString('</w:document>', $truncated);

        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(MaterialExtractionFixtures::docxFromDocumentXml($truncated));
    }

    public function test_fully_closed_document_still_succeeds(): void
    {
        $document = $this->extract(BlueprintImportDocxFixtures::sequentialBodyParagraphs());

        $this->assertSame('ALPHA', $document->blocks[0]['text']);
        $this->assertSame('BETA', $document->blocks[1]['text']);
    }

    public function test_trailing_malformed_content_after_closed_document_fails_closed(): void
    {
        $complete = MaterialExtractionFixtures::documentXml('<w:p><w:r><w:t>Hello</w:t></w:r></w:p>');

        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(MaterialExtractionFixtures::docxFromDocumentXml($complete.'<junk'));
    }

    public function test_libxml_internal_errors_setting_is_restored(): void
    {
        $previous = libxml_use_internal_errors(false);

        try {
            $this->extract(BlueprintImportDocxFixtures::sequentialBodyParagraphs());
            $this->assertFalse(libxml_use_internal_errors(false));
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function test_matrix_preserves_row_and_column_cells(): void
    {
        $rows = $this->extract(BlueprintImportDocxFixtures::caseBMatrix())->blocks[0]['rows'];

        $this->assertCount(4, $rows);
        $this->assertCount(5, $rows[0]['cells']);
        $this->assertTrue($rows[0]['cells'][0]['empty']);
        $this->assertSame(['Bilangan'], $rows[0]['cells'][1]['paragraphs']);
        $this->assertSame(['Pengetahuan/Pemahaman'], $rows[1]['cells'][0]['paragraphs']);
        $this->assertSame(['PN-Sta'], $rows[3]['cells'][4]['paragraphs']);
    }

    public function test_mixed_pg_esai_and_numbering_tokens_remain_raw(): void
    {
        $mixed = $this->extract(BlueprintImportDocxFixtures::mixedPgEsaiTokens())->blocks[0]['rows'][0]['cells'];
        $numbering = $this->extract(BlueprintImportDocxFixtures::numberingTokens())->blocks[0]['rows'][0]['cells'];

        $this->assertSame(['PG 1'], $mixed[0]['paragraphs']);
        $this->assertSame(['Esai 1'], $mixed[1]['paragraphs']);
        $this->assertSame(['PG 1'], $numbering[0]['paragraphs']);
        $this->assertSame(['PG 1–5'], $numbering[1]['paragraphs']);
        $this->assertSame(['Esai 1'], $numbering[2]['paragraphs']);
    }

    public function test_more_than_five_logical_rows_all_survive(): void
    {
        $rows = $this->extract(BlueprintImportDocxFixtures::moreThanFiveLogicalRows())->blocks[0]['rows'];

        $this->assertCount(7, $rows);

        foreach (range(1, 7) as $n) {
            $this->assertSame(
                ['LOGROW_'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)],
                $rows[$n - 1]['cells'][0]['paragraphs'],
            );
        }
    }

    public function test_repeated_extraction_is_deterministic(): void
    {
        $bytes = BlueprintImportDocxFixtures::caseCMetadataThenTable();

        $this->assertSame(
            $this->extract($bytes)->toArray(),
            $this->extract($bytes)->toArray(),
        );
    }

    public function test_malformed_xml_fails_closed(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(BlueprintImportDocxFixtures::malformedXml());
    }

    public function test_malformed_zip_fails_closed(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(MaterialExtractionFixtures::malformedZip());
    }

    public function test_high_compression_ratio_fails_closed(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(MaterialExtractionFixtures::highCompressionRatioDocx());
    }

    public function test_encrypted_archive_fails_closed(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(MaterialExtractionFixtures::encryptedDocx());
    }

    public function test_nested_table_fails_closed(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(MaterialExtractionFixtures::validDocx(
            '<w:tbl><w:tr><w:tc><w:tbl><w:tr><w:tc><w:p><w:r><w:t>INNER</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:tc></w:tr></w:tbl>',
        ));
    }

    public function test_invalid_grid_span_fails_closed(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(MaterialExtractionFixtures::validDocx(
            '<w:tbl><w:tr><w:tc><w:tcPr><w:gridSpan w:val="0"/></w:tcPr><w:p><w:r><w:t>X</w:t></w:r></w:p></w:tc></w:tr></w:tbl>',
        ));
    }

    public function test_structure_bound_overflow_fails_closed_without_partial_success(): void
    {
        config(['question_blueprint.import_structure_max_blocks' => 2]);

        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(BlueprintImportDocxFixtures::metadataParagraphsOnly());
    }

    public function test_entity_payload_is_not_substituted_into_structure(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<!DOCTYPE doc [<!ENTITY xxe "INJECTED">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body></w:document>';

        try {
            $document = (new BlueprintImportDocxStructureExtractor)->extract(
                MaterialExtractionFixtures::docxFromDocumentXml($xml),
            );
        } catch (UnrecoverableMaterialExtractionException) {
            $this->assertTrue(true);

            return;
        }

        $encoded = json_encode($document->toArray());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('INJECTED', $encoded);
    }

    public function test_disables_network_dtd_and_entity_substitution_without_global_loader_mutation(): void
    {
        $source = (string) file_get_contents(base_path(
            'app/Services/QuestionBlueprints/BlueprintImportDocxStructureExtractor.php',
        ));

        $this->assertStringContainsString('LIBXML_NONET', $source);
        $this->assertStringContainsString('XMLReader::LOADDTD, false', $source);
        $this->assertStringContainsString('XMLReader::SUBST_ENTITIES, false', $source);
        $this->assertStringNotContainsString('libxml_disable_entity_loader', $source);
        $this->assertStringContainsString('DocxExtractor::MAX_ZIP_UNCOMPRESSED_BYTES', $source);
        $this->assertStringContainsString('DocxExtractor::MAX_DOCUMENT_XML_BYTES', $source);
    }

    public function test_temp_files_are_cleaned_after_success_and_failure(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bis-test-'.bin2hex(random_bytes(8));

        if (! mkdir($directory) && ! is_dir($directory)) {
            $this->fail('Unable to create extractor temp directory.');
        }

        try {
            (new BlueprintImportDocxStructureExtractor($directory))
                ->extract(BlueprintImportDocxFixtures::sequentialBodyParagraphs());
            $this->assertSame([], $this->entries($directory));

            try {
                (new BlueprintImportDocxStructureExtractor($directory))
                    ->extract(MaterialExtractionFixtures::malformedZip());
                $this->fail('Malformed ZIP must be unrecoverable.');
            } catch (UnrecoverableMaterialExtractionException) {
                // expected
            }

            $this->assertSame([], $this->entries($directory));
        } finally {
            foreach ($this->entries($directory) as $entry) {
                @unlink($directory.DIRECTORY_SEPARATOR.$entry);
            }

            @rmdir($directory);
        }
    }

    public function test_schema_version_constant_matches_config(): void
    {
        $this->assertSame(
            'blueprint-import-structure-v1',
            ImportStructuredDocument::SCHEMA_VERSION,
        );
        $this->assertSame(
            ImportStructuredDocument::SCHEMA_VERSION,
            config('question_blueprint.import_structure_schema_version'),
        );
    }

    private function extract(string $bytes): ImportStructuredDocument
    {
        return (new BlueprintImportDocxStructureExtractor)->extract($bytes);
    }

    private function headerRowTable(?string $val): string
    {
        $header = match ($val) {
            null => '',
            '' => '<w:tblHeader/>',
            default => '<w:tblHeader w:val="'.htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>',
        };

        return MaterialExtractionFixtures::validDocx(
            '<w:tbl><w:tblPr></w:tblPr><w:tblGrid><w:gridCol w:w="1440"/></w:tblGrid>'
            .'<w:tr><w:trPr>'.$header.'</w:trPr>'
            .'<w:tc><w:tcPr></w:tcPr><w:p><w:r><w:t>HDR</w:t></w:r></w:p></w:tc></w:tr></w:tbl>',
        );
    }

    /**
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }
}
