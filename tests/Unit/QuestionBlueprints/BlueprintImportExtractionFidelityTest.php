<?php

declare(strict_types=1);

namespace Tests\Unit\QuestionBlueprints;

use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Services\Materials\Extraction\DocxExtractor;
use Tests\Support\QuestionBlueprints\BlueprintImportDocxFixtures;
use Tests\TestCase;

class BlueprintImportExtractionFidelityTest extends TestCase
{
    public function test_unique_token_2x3_table_flattens_cells_as_paragraph_stream(): void
    {
        $this->assertSame(
            "R0C0\nR0C1\nR0C2\nR1C0\nR1C1\nR1C2\n",
            $this->extract(BlueprintImportDocxFixtures::uniqueTokenTable2x3()),
        );
    }

    public function test_case_a_direct_row_blueprint_includes_durasi_column_tokens(): void
    {
        $this->assertSame(
            "No\nDurasi Tes\nKD/CP\nMateri\nIndikator\nLevel\nBentuk Soal\n"
            ."1\n90 menit\n3.1 Mengidentifikasi struktur teks prosedur\nStruktur Teks Prosedur\n"
            ."Menentukan struktur utama teks prosedur\nC1\nPG\n",
            $this->extract(BlueprintImportDocxFixtures::caseADirectRowBlueprint()),
        );
    }

    public function test_case_c_metadata_paragraphs_then_table_are_one_paragraph_stream(): void
    {
        $this->assertSame(
            "Jenis Sekolah: SMP\nMata Pelajaran: Bahasa Indonesia\nKurikulum: Merdeka\nJumlah Soal: 40\n"
            ."No\nButir\n1\nMenentukan gagasan utama\n",
            $this->extract(BlueprintImportDocxFixtures::caseCMetadataThenTable()),
        );
    }

    public function test_synthetic_tblheader_property_does_not_change_extracted_text(): void
    {
        $withHeader = $this->extract(BlueprintImportDocxFixtures::syntheticTblHeaderSemantics());
        $withoutHeader = $this->extract(BlueprintImportDocxFixtures::syntheticTblHeaderWithoutProperty());

        $this->assertSame("HDR_A\nHDR_B\nDAT_A\nDAT_B\n", $withHeader);
        $this->assertSame("HDR_A\nHDR_B\nDAT_A\nDAT_B\n", $withoutHeader);
        $this->assertSame($withoutHeader, $withHeader);
    }

    public function test_physically_duplicated_header_rows_repeat_visible_tokens(): void
    {
        $this->assertSame(
            "HDR_A\nHDR_B\nHDR_A\nHDR_B\nDAT_A\nDAT_B\n",
            $this->extract(BlueprintImportDocxFixtures::physicallyDuplicatedHeaderRows()),
        );
    }

    public function test_case_d_vertical_merge_emits_empty_continue_paragraphs(): void
    {
        $this->assertSame(
            "Menjelaskan unsur, senyawa, dan campuran\nMenuliskan contoh unsur\nPG 1\n"
            ."\nMenuliskan contoh senyawa\nPG 2\n"
            ."\nMenyusun argumen pemisahan campuran\nEsai 1\n",
            $this->extract(BlueprintImportDocxFixtures::caseDVerticalMerge()),
        );
    }

    public function test_horizontal_gridspan_is_absent_from_extracted_text(): void
    {
        $this->assertSame(
            "SPAN_AB\nSIDE_C\nCOL_A\nCOL_B\nCOL_C\n",
            $this->extract(BlueprintImportDocxFixtures::horizontalGridSpan()),
        );
    }

    public function test_multi_paragraph_cell_emits_one_newline_per_paragraph(): void
    {
        $this->assertSame(
            "LINE1\nLINE2\nNEXT\n",
            $this->extract(BlueprintImportDocxFixtures::multiParagraphCell()),
        );
    }

    public function test_empty_middle_cell_emits_an_empty_paragraph(): void
    {
        $this->assertSame(
            "LEFT\n\nRIGHT\n",
            $this->extract(BlueprintImportDocxFixtures::emptyMiddleCell()),
        );
    }

    public function test_mixed_pg_and_esai_tokens_remain_raw(): void
    {
        $this->assertSame(
            "PG 1\nEsai 1\n",
            $this->extract(BlueprintImportDocxFixtures::mixedPgEsaiTokens()),
        );
    }

    public function test_numbering_tokens_remain_raw(): void
    {
        $this->assertSame(
            "PG 1\nPG 1–5\nEsai 1\n",
            $this->extract(BlueprintImportDocxFixtures::numberingTokens()),
        );
    }

    public function test_case_e_taxonomy_table_is_structural_only(): void
    {
        $this->assertSame(
            "Kode\nDeskripsi\nC1\nMengingat\nC2\nMemahami\nC3\nMengaplikasikan\n",
            $this->extract(BlueprintImportDocxFixtures::caseETaxonomyTable()),
        );
    }

    public function test_case_b_matrix_flattens_axes_and_cells(): void
    {
        $this->assertSame(
            "\nBilangan\nAljabar\nGeometri\nStatistika\n"
            ."Pengetahuan/Pemahaman\nPP-Bil\nPP-Alj\nPP-Geo\nPP-Sta\n"
            ."Aplikasi\nAP-Bil\nAP-Alj\nAP-Geo\nAP-Sta\n"
            ."Penalaran\nPN-Bil\nPN-Alj\nPN-Geo\nPN-Sta\n",
            $this->extract(BlueprintImportDocxFixtures::caseBMatrix()),
        );
    }

    public function test_more_than_five_logical_rows_retain_all_unique_tokens(): void
    {
        $text = $this->extract(BlueprintImportDocxFixtures::moreThanFiveLogicalRows());

        $this->assertSame(
            "LOGROW_01\nLOGROW_02\nLOGROW_03\nLOGROW_04\nLOGROW_05\nLOGROW_06\nLOGROW_07\n",
            $text,
        );

        foreach (['LOGROW_01', 'LOGROW_02', 'LOGROW_03', 'LOGROW_04', 'LOGROW_05', 'LOGROW_06', 'LOGROW_07'] as $token) {
            $this->assertStringContainsString($token, $text);
        }
    }

    public function test_malformed_xml_remains_unrecoverable(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extract(BlueprintImportDocxFixtures::malformedXml());
    }

    public function test_adjacent_cells_collide_with_sequential_paragraphs(): void
    {
        $cells = $this->extract(BlueprintImportDocxFixtures::adjacentTableCells());
        $paragraphs = $this->extract(BlueprintImportDocxFixtures::sequentialBodyParagraphs());

        $this->assertSame("ALPHA\nBETA\n", $cells);
        $this->assertSame("ALPHA\nBETA\n", $paragraphs);
        $this->assertSame($paragraphs, $cells);
    }

    public function test_multi_paragraph_cell_collides_with_adjacent_cells(): void
    {
        $multi = $this->extract(BlueprintImportDocxFixtures::multiParagraphSingleCell());
        $adjacent = $this->extract(BlueprintImportDocxFixtures::multipleAdjacentCells());

        $this->assertSame("LINE1\nLINE2\n", $multi);
        $this->assertSame("LINE1\nLINE2\n", $adjacent);
        $this->assertSame($adjacent, $multi);
    }

    public function test_empty_middle_cell_differs_from_absent_middle_cell(): void
    {
        $empty = $this->extract(BlueprintImportDocxFixtures::emptyMiddleCell());
        $absent = $this->extract(BlueprintImportDocxFixtures::absentMiddleCell());

        $this->assertSame("LEFT\n\nRIGHT\n", $empty);
        $this->assertSame("LEFT\nRIGHT\n", $absent);
        $this->assertNotSame($absent, $empty);
    }

    public function test_merged_competency_differs_from_unmerged_visible_strings(): void
    {
        $merged = $this->extract(BlueprintImportDocxFixtures::caseDVerticalMerge());
        $unmerged = $this->extract(BlueprintImportDocxFixtures::unmergedEquivalentVisibleStrings());

        $this->assertSame(
            "Menjelaskan unsur, senyawa, dan campuran\nMenuliskan contoh unsur\nPG 1\n"
            ."\nMenuliskan contoh senyawa\nPG 2\n"
            ."\nMenyusun argumen pemisahan campuran\nEsai 1\n",
            $merged,
        );
        $this->assertSame(
            "Menjelaskan unsur, senyawa, dan campuran\nMenuliskan contoh unsur\nPG 1\n"
            ."Menuliskan contoh senyawa\nPG 2\n"
            ."Menyusun argumen pemisahan campuran\nEsai 1\n",
            $unmerged,
        );
        $this->assertNotSame($unmerged, $merged);
    }

    public function test_metadata_paragraphs_collide_with_equivalent_flattened_table(): void
    {
        $paragraphs = $this->extract(BlueprintImportDocxFixtures::metadataParagraphsOnly());
        $table = $this->extract(BlueprintImportDocxFixtures::equivalentFlattenedTableContent());

        $this->assertSame(
            "Jenis Sekolah: SMP\nMata Pelajaran: Bahasa Indonesia\nKurikulum: Merdeka\nJumlah Soal: 40\n",
            $paragraphs,
        );
        $this->assertSame(
            "Jenis Sekolah: SMP\nMata Pelajaran: Bahasa Indonesia\nKurikulum: Merdeka\nJumlah Soal: 40\n",
            $table,
        );
        $this->assertSame($table, $paragraphs);
    }

    private function extract(string $bytes): string
    {
        return (new DocxExtractor)->extract($bytes);
    }
}
