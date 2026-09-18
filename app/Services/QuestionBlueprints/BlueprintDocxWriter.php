<?php

declare(strict_types=1);

namespace App\Services\QuestionBlueprints;

use App\Enums\BlueprintLifecycleStatus;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintRow;
use App\Support\QuestionBlueprints\BlueprintDocxFilename;
use App\Support\QuestionBlueprints\BlueprintDocxSaver;
use App\Support\QuestionBlueprints\BlueprintDocxTempFile;
use App\Support\QuestionBlueprints\PhpWordBlueprintDocxSaver;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Section;
use PhpOffice\PhpWord\Style\Table;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BlueprintDocxWriter
{
    private const PAGE_WIDTH = 16838;

    private const PAGE_HEIGHT = 11906;

    private const MARGIN = 851;

    private const LABEL_WIDTH = 3200;

    public function __construct(
        private ?BlueprintDocxTempFile $temp = null,
        private ?BlueprintDocxSaver $saver = null,
    ) {
        $this->temp ??= new BlueprintDocxTempFile;
        $this->saver ??= new PhpWordBlueprintDocxSaver;
    }

    public function download(QuestionBlueprint $blueprint, bool $historical): BinaryFileResponse
    {
        if ($blueprint->lifecycle_status !== BlueprintLifecycleStatus::Confirmed) {
            throw new RuntimeException('Only confirmed blueprints can be downloaded.');
        }

        $blueprint->loadMissing(['rows.contexts.profileElement', 'rows.contexts.profileChunk', 'material']);

        return $this->stream($this->document($blueprint, $historical), BlueprintDocxFilename::for((string) $blueprint->title));
    }

    public function stream(PhpWord $phpWord, string $filename): BinaryFileResponse
    {
        $raw = null;
        $docx = null;
        $keepDocx = false;

        try {
            $raw = $this->temp->createRaw();

            try {
                $docx = $this->temp->moveToDocx($raw);
                $raw = null;
            } catch (Throwable $exception) {
                $this->temp->delete($raw);
                $this->temp->delete($raw.'.docx');
                $raw = null;

                throw $exception;
            }

            $this->saver->save($phpWord, $docx);
            $keepDocx = true;

            $response = new BinaryFileResponse($docx, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
            $response->deleteFileAfterSend(true);
            $response->setContentDisposition('attachment', $filename);

            return $response;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            if ($raw !== null) {
                $this->temp->delete($raw);
            }

            if (! $keepDocx) {
                $this->temp->delete($docx);
            }
        }
    }

    private function document(QuestionBlueprint $blueprint, bool $historical): PhpWord
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);
        $phpWord->addTitleStyle(1, ['name' => 'Calibri', 'size' => 16, 'bold' => true, 'color' => '1F4E79'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $phpWord->addTitleStyle(2, ['name' => 'Calibri', 'size' => 12, 'bold' => true, 'color' => '344054'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);

        $info = $phpWord->getDocInfo();
        $info->setCreator('AI Question Bank');
        $info->setLastModifiedBy('AI Question Bank');
        $info->setCompany('AI Question Bank');
        $info->setTitle((string) $blueprint->title);
        $info->setDescription('Kisi-kisi');

        $section = $phpWord->addSection();
        $style = $section->getStyle();
        $style->setOrientation(Section::ORIENTATION_LANDSCAPE);
        $style->setPaperSize('A4');
        $style->setPageSizeW(self::PAGE_WIDTH);
        $style->setPageSizeH(self::PAGE_HEIGHT);
        $style->setMarginTop(self::MARGIN);
        $style->setMarginBottom(self::MARGIN);
        $style->setMarginLeft(self::MARGIN);
        $style->setMarginRight(self::MARGIN);

        $section->addTitle('KISI-KISI PENULISAN SOAL', 1);
        $section->addTitle((string) $blueprint->title, 2);
        $section->addTextBreak(1);

        $this->addMeta($section, 'Materi', (string) ($blueprint->material?->title ?? ''));
        $this->addMeta($section, 'Tipe assessment', $blueprint->assessment_type->label());
        $this->addMeta($section, 'Versi', (string) $blueprint->version);
        $this->addMeta($section, 'Total soal', (string) $blueprint->rows->sum('requested_count'));

        if ($blueprint->confirmed_at !== null) {
            $this->addMeta(
                $section,
                'Dikonfirmasi',
                $blueprint->confirmed_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            );
        }

        if ($historical) {
            $section->addTextBreak(1);
            $section->addText(
                'Salinan historis. Materi atau profil telah berubah sejak kisi-kisi ini dikonfirmasi.',
                ['italic' => true, 'color' => '7A5400'],
            );
        }

        $section->addTextBreak(1);

        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => 'BAC5D6',
            'cellMargin' => 80,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
            'layout' => Table::LAYOUT_FIXED,
        ]);

        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        $this->addHeaderCell($table, 500, 'No.');
        $this->addHeaderCell($table, 2500, 'Kompetensi / Tujuan Pembelajaran');
        $this->addHeaderCell($table, 1500, 'Materi');
        $this->addHeaderCell($table, 3000, 'Indikator Soal');
        $this->addHeaderCell($table, 1200, 'Level Kognitif');
        $this->addHeaderCell($table, 1200, 'Bentuk Soal');
        $this->addHeaderCell($table, 800, 'No. Soal');

        $questionCursor = 1;
        $rowNumber = 1;

        foreach ($blueprint->rows as $row) {
            $start = $questionCursor;
            $end = $start + $row->requested_count - 1;
            $questionRange = $start === $end ? (string) $start : "{$start}–{$end}";
            $questionCursor = $end + 1;

            $table->addRow(null, ['cantSplit' => true]);
            $table->addCell(500, ['valign' => 'top'])->addText((string) $rowNumber++, ['size' => 11, 'name' => 'Calibri']);
            $table->addCell(2500, ['valign' => 'top'])->addText((string) $row->objective, ['size' => 11, 'name' => 'Calibri']);
            $table->addCell(1500, ['valign' => 'top'])->addText((string) $row->topic, ['size' => 11, 'name' => 'Calibri']);
            $table->addCell(3000, ['valign' => 'top'])->addText((string) $row->indicator, ['size' => 11, 'name' => 'Calibri']);
            $table->addCell(1200, ['valign' => 'top'])->addText($row->cognitive_level->label(), ['size' => 11, 'name' => 'Calibri']);
            $table->addCell(1200, ['valign' => 'top'])->addText($row->question_type->label(), ['size' => 11, 'name' => 'Calibri']);
            $table->addCell(800, ['valign' => 'top'])->addText($questionRange, ['size' => 11, 'name' => 'Calibri']);
        }

        return $phpWord;
    }

    private function addMeta(\PhpOffice\PhpWord\Element\Section $section, string $label, string $value): void
    {
        $run = $section->addTextRun();
        $run->addText($label.': ', ['bold' => true, 'size' => 11]);
        $run->addText($value, ['size' => 11]);
    }

    private function addHeaderCell(\PhpOffice\PhpWord\Element\Table $table, int $width, string $text): void
    {
        $table->addCell($width, ['bgColor' => '1F4E79', 'valign' => 'center'])
            ->addText($text, ['bold' => true, 'color' => 'FFFFFF', 'size' => 11, 'name' => 'Calibri']);
    }
}
