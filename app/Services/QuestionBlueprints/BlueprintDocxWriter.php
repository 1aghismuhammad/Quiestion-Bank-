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
        $phpWord->addTitleStyle(1, ['name' => 'Calibri', 'size' => 18, 'bold' => true, 'color' => '1F4E79']);
        $phpWord->addTitleStyle(2, ['name' => 'Calibri', 'size' => 13, 'bold' => true, 'color' => '1F4E79']);

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

        $section->addTitle((string) $blueprint->title, 1);
        $section->addText('Kisi-kisi penilaian', ['italic' => true, 'size' => 12, 'color' => '344054']);
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

        foreach ($blueprint->rows as $row) {
            $section->addTextBreak(1);
            $section->addTitle('Baris '.(string) $row->sort_order, 2);
            $this->addRowTable($section, $row);
        }

        return $phpWord;
    }

    private function addMeta(\PhpOffice\PhpWord\Element\Section $section, string $label, string $value): void
    {
        $run = $section->addTextRun();
        $run->addText($label.': ', ['bold' => true, 'size' => 11]);
        $run->addText($value, ['size' => 11]);
    }

    private function addRowTable(\PhpOffice\PhpWord\Element\Section $section, QuestionBlueprintRow $row): void
    {
        $valueWidth = self::PAGE_WIDTH - (2 * self::MARGIN) - self::LABEL_WIDTH;
        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => 'BAC5D6',
            'cellMargin' => 80,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
            'layout' => Table::LAYOUT_FIXED,
        ]);

        $table->addRow(360, ['tblHeader' => true, 'cantSplit' => true]);
        $this->addHeaderCell($table, self::LABEL_WIDTH, 'Uraian');
        $this->addHeaderCell($table, $valueWidth, 'Isi');

        foreach ($this->rowFields($row) as $label => $value) {
            $table->addRow(null, ['cantSplit' => true]);
            $table->addCell(self::LABEL_WIDTH, ['bgColor' => 'E9EEF8', 'valign' => 'top'])
                ->addText($label, ['bold' => true, 'size' => 11, 'name' => 'Calibri']);
            $table->addCell($valueWidth, ['valign' => 'top'])
                ->addText($value, ['size' => 11, 'name' => 'Calibri']);
        }
    }

    private function addHeaderCell(\PhpOffice\PhpWord\Element\Table $table, int $width, string $text): void
    {
        $table->addCell($width, ['bgColor' => '1F4E79', 'valign' => 'center'])
            ->addText($text, ['bold' => true, 'color' => 'FFFFFF', 'size' => 11, 'name' => 'Calibri']);
    }

    /**
     * @return array<string, string>
     */
    private function rowFields(QuestionBlueprintRow $row): array
    {
        return [
            'Tujuan' => (string) $row->objective,
            'Topik' => (string) $row->topic,
            'Indikator' => (string) $row->indicator,
            'Level kognitif' => $row->cognitive_level->label(),
            'Kesulitan' => $row->difficulty->label(),
            'Tipe soal' => $row->question_type->label(),
            'Jumlah soal' => (string) $row->requested_count,
            'Sumber' => $this->sourceLabel($row),
        ];
    }

    private function sourceLabel(QuestionBlueprintRow $row): string
    {
        $parts = [];

        foreach ($row->contexts as $context) {
            $element = $context->profileElement;

            if ($element !== null && trim((string) $element->text) !== '') {
                $text = trim(preg_replace('/\s+/u', ' ', mb_substr((string) $element->text, 0, 120, 'UTF-8')) ?? '');
                $parts[] = $element->kind->label().': '.$text;

                continue;
            }

            $chunk = $context->profileChunk;

            if ($chunk !== null) {
                $parts[] = 'Cuplikan profil '.((int) $chunk->chunk_index + 1);

                continue;
            }

            $parts[] = 'Cuplikan profil';
        }

        return $parts === [] ? 'Profil materi' : implode('; ', $parts);
    }
}
