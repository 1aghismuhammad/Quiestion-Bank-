<?php

declare(strict_types=1);

namespace Tests\Support\QuestionBlueprints;

use Tests\Support\Materials\MaterialExtractionFixtures;

final class BlueprintImportDocxFixtures
{
    public static function uniqueTokenTable2x3(): string
    {
        return self::docx(self::table(3,
            self::row([self::cell(['R0C0']), self::cell(['R0C1']), self::cell(['R0C2'])]),
            self::row([self::cell(['R1C0']), self::cell(['R1C1']), self::cell(['R1C2'])]),
        ));
    }

    public static function caseADirectRowBlueprint(): string
    {
        return self::docx(self::table(7,
            self::row([
                self::cell(['No']),
                self::cell(['Durasi Tes']),
                self::cell(['KD/CP']),
                self::cell(['Materi']),
                self::cell(['Indikator']),
                self::cell(['Level']),
                self::cell(['Bentuk Soal']),
            ]),
            self::row([
                self::cell(['1']),
                self::cell(['90 menit']),
                self::cell(['3.1 Mengidentifikasi struktur teks prosedur']),
                self::cell(['Struktur Teks Prosedur']),
                self::cell(['Menentukan struktur utama teks prosedur']),
                self::cell(['C1']),
                self::cell(['PG']),
            ]),
        ));
    }

    public static function caseCMetadataThenTable(): string
    {
        $body = self::paragraph('Jenis Sekolah: SMP')
            .self::paragraph('Mata Pelajaran: Bahasa Indonesia')
            .self::paragraph('Kurikulum: Merdeka')
            .self::paragraph('Jumlah Soal: 40')
            .self::table(2,
                self::row([self::cell(['No']), self::cell(['Butir'])]),
                self::row([self::cell(['1']), self::cell(['Menentukan gagasan utama'])]),
            );

        return self::docx($body);
    }

    public static function syntheticTblHeaderSemantics(): string
    {
        return self::docx(self::table(2,
            self::row([self::cell(['HDR_A']), self::cell(['HDR_B'])], tblHeader: true),
            self::row([self::cell(['DAT_A']), self::cell(['DAT_B'])]),
        ));
    }

    public static function syntheticTblHeaderWithoutProperty(): string
    {
        return self::docx(self::table(2,
            self::row([self::cell(['HDR_A']), self::cell(['HDR_B'])]),
            self::row([self::cell(['DAT_A']), self::cell(['DAT_B'])]),
        ));
    }

    public static function physicallyDuplicatedHeaderRows(): string
    {
        return self::docx(self::table(2,
            self::row([self::cell(['HDR_A']), self::cell(['HDR_B'])], tblHeader: true),
            self::row([self::cell(['HDR_A']), self::cell(['HDR_B'])], tblHeader: true),
            self::row([self::cell(['DAT_A']), self::cell(['DAT_B'])]),
        ));
    }

    public static function caseDVerticalMerge(): string
    {
        return self::docx(self::table(3,
            self::row([
                self::cell(['Menjelaskan unsur, senyawa, dan campuran'], vMerge: 'restart'),
                self::cell(['Menuliskan contoh unsur']),
                self::cell(['PG 1']),
            ]),
            self::row([
                self::cell([''], vMerge: 'continue'),
                self::cell(['Menuliskan contoh senyawa']),
                self::cell(['PG 2']),
            ]),
            self::row([
                self::cell([''], vMerge: 'continue'),
                self::cell(['Menyusun argumen pemisahan campuran']),
                self::cell(['Esai 1']),
            ]),
        ));
    }

    public static function unmergedEquivalentVisibleStrings(): string
    {
        return self::docx(
            self::paragraph('Menjelaskan unsur, senyawa, dan campuran')
            .self::paragraph('Menuliskan contoh unsur')
            .self::paragraph('PG 1')
            .self::paragraph('Menuliskan contoh senyawa')
            .self::paragraph('PG 2')
            .self::paragraph('Menyusun argumen pemisahan campuran')
            .self::paragraph('Esai 1'),
        );
    }

    public static function horizontalGridSpan(): string
    {
        return self::docx(self::table(3,
            self::row([
                self::cell(['SPAN_AB'], gridSpan: 2),
                self::cell(['SIDE_C']),
            ]),
            self::row([
                self::cell(['COL_A']),
                self::cell(['COL_B']),
                self::cell(['COL_C']),
            ]),
        ));
    }

    public static function multiParagraphCell(): string
    {
        return self::docx(self::table(2,
            self::row([
                self::cell(['LINE1', 'LINE2']),
                self::cell(['NEXT']),
            ]),
        ));
    }

    public static function multiParagraphSingleCell(): string
    {
        return self::docx(self::table(1,
            self::row([
                self::cell(['LINE1', 'LINE2']),
            ]),
        ));
    }

    public static function emptyMiddleCell(): string
    {
        return self::docx(self::table(3,
            self::row([
                self::cell(['LEFT']),
                self::cell(['']),
                self::cell(['RIGHT']),
            ]),
        ));
    }

    public static function absentMiddleCell(): string
    {
        return self::docx(self::table(2,
            self::row([
                self::cell(['LEFT']),
                self::cell(['RIGHT']),
            ]),
        ));
    }

    public static function mixedPgEsaiTokens(): string
    {
        return self::docx(self::table(2,
            self::row([
                self::cell(['PG 1']),
                self::cell(['Esai 1']),
            ]),
        ));
    }

    public static function numberingTokens(): string
    {
        return self::docx(self::table(3,
            self::row([
                self::cell(['PG 1']),
                self::cell(['PG 1–5']),
                self::cell(['Esai 1']),
            ]),
        ));
    }

    public static function caseETaxonomyTable(): string
    {
        return self::docx(self::table(2,
            self::row([self::cell(['Kode']), self::cell(['Deskripsi'])]),
            self::row([self::cell(['C1']), self::cell(['Mengingat'])]),
            self::row([self::cell(['C2']), self::cell(['Memahami'])]),
            self::row([self::cell(['C3']), self::cell(['Mengaplikasikan'])]),
        ));
    }

    public static function caseBMatrix(): string
    {
        return self::docx(self::table(5,
            self::row([
                self::cell(['']),
                self::cell(['Bilangan']),
                self::cell(['Aljabar']),
                self::cell(['Geometri']),
                self::cell(['Statistika']),
            ]),
            self::row([
                self::cell(['Pengetahuan/Pemahaman']),
                self::cell(['PP-Bil']),
                self::cell(['PP-Alj']),
                self::cell(['PP-Geo']),
                self::cell(['PP-Sta']),
            ]),
            self::row([
                self::cell(['Aplikasi']),
                self::cell(['AP-Bil']),
                self::cell(['AP-Alj']),
                self::cell(['AP-Geo']),
                self::cell(['AP-Sta']),
            ]),
            self::row([
                self::cell(['Penalaran']),
                self::cell(['PN-Bil']),
                self::cell(['PN-Alj']),
                self::cell(['PN-Geo']),
                self::cell(['PN-Sta']),
            ]),
        ));
    }

    public static function moreThanFiveLogicalRows(): string
    {
        $rows = [];

        for ($n = 1; $n <= 7; $n++) {
            $rows[] = self::row([self::cell(['LOGROW_'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)])]);
        }

        return self::docx(self::table(1, ...$rows));
    }

    public static function adjacentTableCells(): string
    {
        return self::docx(self::table(2,
            self::row([
                self::cell(['ALPHA']),
                self::cell(['BETA']),
            ]),
        ));
    }

    public static function sequentialBodyParagraphs(): string
    {
        return self::docx(
            self::paragraph('ALPHA')
            .self::paragraph('BETA'),
        );
    }

    public static function multipleAdjacentCells(): string
    {
        return self::docx(self::table(2,
            self::row([
                self::cell(['LINE1']),
                self::cell(['LINE2']),
            ]),
        ));
    }

    public static function metadataParagraphsOnly(): string
    {
        return self::docx(
            self::paragraph('Jenis Sekolah: SMP')
            .self::paragraph('Mata Pelajaran: Bahasa Indonesia')
            .self::paragraph('Kurikulum: Merdeka')
            .self::paragraph('Jumlah Soal: 40'),
        );
    }

    public static function equivalentFlattenedTableContent(): string
    {
        return self::docx(self::table(1,
            self::row([self::cell(['Jenis Sekolah: SMP'])]),
            self::row([self::cell(['Mata Pelajaran: Bahasa Indonesia'])]),
            self::row([self::cell(['Kurikulum: Merdeka'])]),
            self::row([self::cell(['Jumlah Soal: 40'])]),
        ));
    }

    public static function malformedXml(): string
    {
        return MaterialExtractionFixtures::malformedXmlDocx();
    }

    private static function docx(string $bodyXml): string
    {
        return MaterialExtractionFixtures::validDocx($bodyXml);
    }

    private static function table(int $columnCount, string ...$rows): string
    {
        $grid = '<w:tblGrid>';

        for ($i = 0; $i < $columnCount; $i++) {
            $grid .= '<w:gridCol w:w="1440"/>';
        }

        $grid .= '</w:tblGrid>';

        return '<w:tbl><w:tblPr></w:tblPr>'.$grid.implode('', $rows).'</w:tbl>';
    }

    /**
     * @param  list<string>  $cells
     */
    private static function row(array $cells, bool $tblHeader = false): string
    {
        $pr = $tblHeader ? '<w:trPr><w:tblHeader/></w:trPr>' : '<w:trPr></w:trPr>';

        return '<w:tr>'.$pr.implode('', $cells).'</w:tr>';
    }

    /**
     * @param  list<string>  $paragraphs
     */
    private static function cell(array $paragraphs, ?int $gridSpan = null, ?string $vMerge = null): string
    {
        if ($paragraphs === []) {
            $paragraphs = [''];
        }

        $innerPr = '';

        if ($gridSpan !== null) {
            $innerPr .= '<w:gridSpan w:val="'.(string) $gridSpan.'"/>';
        }

        if ($vMerge === 'restart') {
            $innerPr .= '<w:vMerge w:val="restart"/>';
        } elseif ($vMerge === 'continue') {
            $innerPr .= '<w:vMerge/>';
        }

        $pr = $innerPr === '' ? '<w:tcPr></w:tcPr>' : '<w:tcPr>'.$innerPr.'</w:tcPr>';

        $body = '';

        foreach ($paragraphs as $paragraph) {
            $body .= self::paragraph($paragraph);
        }

        return '<w:tc>'.$pr.$body.'</w:tc>';
    }

    private static function paragraph(string $text): string
    {
        if ($text === '') {
            return '<w:p></w:p>';
        }

        return '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';
    }
}
