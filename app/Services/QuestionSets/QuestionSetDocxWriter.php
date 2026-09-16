<?php

declare(strict_types=1);

namespace App\Services\QuestionSets;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionSet;
use App\Support\QuestionSets\PhpWordQuestionSetDocxSaver;
use App\Support\QuestionSets\QuestionSetDocxFilename;
use App\Support\QuestionSets\QuestionSetDocxSaver;
use App\Support\QuestionSets\QuestionSetDocxTempFile;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Section;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class QuestionSetDocxWriter
{
    private const PAGE_WIDTH = 11906;

    private const PAGE_HEIGHT = 16838;

    private const MARGIN = 1134;

    private const ESSAY_ANSWER_LINES = 5;

    public function __construct(
        private ?QuestionSetDocxTempFile $temp = null,
        private ?QuestionSetDocxSaver $saver = null,
    ) {
        $this->temp ??= new QuestionSetDocxTempFile;
        $this->saver ??= new PhpWordQuestionSetDocxSaver;
    }

    public function download(QuestionSet $questionSet, bool $teacher): BinaryFileResponse
    {
        $questionSet->loadMissing(['questions.options']);

        return $this->stream(
            $this->document($questionSet, $teacher),
            QuestionSetDocxFilename::for((string) $questionSet->title, $teacher),
        );
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

            $response = $this->createAttachmentResponse($docx, $filename);
            $keepDocx = true;

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

    protected function createAttachmentResponse(string $docx, string $filename): BinaryFileResponse
    {
        $response = new BinaryFileResponse($docx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition('attachment', $filename);

        return $response;
    }

    private function document(QuestionSet $questionSet, bool $teacher): PhpWord
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);
        $phpWord->addTitleStyle(1, ['name' => 'Calibri', 'size' => 18, 'bold' => true, 'color' => '1F4E79']);

        $info = $phpWord->getDocInfo();
        $info->setCreator('AI Question Bank');
        $info->setLastModifiedBy('AI Question Bank');
        $info->setCompany('AI Question Bank');
        $info->setTitle((string) $questionSet->title);
        $info->setDescription($teacher ? 'Kunci jawaban' : 'Lembar soal');

        $section = $phpWord->addSection();
        $style = $section->getStyle();
        $style->setOrientation(Section::ORIENTATION_PORTRAIT);
        $style->setPaperSize('A4');
        $style->setPageSizeW(self::PAGE_WIDTH);
        $style->setPageSizeH(self::PAGE_HEIGHT);
        $style->setMarginTop(self::MARGIN);
        $style->setMarginBottom(self::MARGIN);
        $style->setMarginLeft(self::MARGIN);
        $style->setMarginRight(self::MARGIN);

        $section->addTitle((string) $questionSet->title, 1);
        $section->addText(
            $teacher ? 'Kunci jawaban dan pembahasan' : 'Lembar soal siswa',
            ['italic' => true, 'size' => 12, 'color' => '344054'],
        );
        $section->addTextBreak(1);
        $this->addMeta($section, 'Jumlah soal', (string) $questionSet->total_question);

        foreach ($questionSet->questions as $question) {
            $section->addTextBreak(1);
            $this->addQuestion($section, $question, $teacher);
        }

        return $phpWord;
    }

    private function addMeta(\PhpOffice\PhpWord\Element\Section $section, string $label, string $value): void
    {
        $run = $section->addTextRun();
        $run->addText($label.': ', ['bold' => true, 'size' => 11]);
        $run->addText($value, ['size' => 11]);
    }

    private function addQuestion(\PhpOffice\PhpWord\Element\Section $section, Question $question, bool $teacher): void
    {
        $typeLabel = $question->question_type instanceof QuestionType
            ? $question->question_type->label()
            : '';
        $difficultyLabel = $teacher ? ($question->difficulty_level?->label() ?? '') : '';

        $header = (string) $question->question_number.'. '.$question->question_text;
        $section->addText($header, ['bold' => true, 'size' => 11], ['keepLines' => true]);

        if ($typeLabel !== '' || $difficultyLabel !== '') {
            $meta = trim($typeLabel.($difficultyLabel !== '' ? ' · '.$difficultyLabel : ''));
            $section->addText($meta, ['italic' => true, 'size' => 10, 'color' => '667085']);
        }

        match ($question->question_type) {
            QuestionType::MULTIPLE_CHOICE => $this->addMcqBody($section, $question, $teacher),
            QuestionType::TRUE_FALSE => $this->addTrueFalseBody($section, $question, $teacher),
            QuestionType::ESSAY => $this->addEssayBody($section, $question, $teacher),
            default => null,
        };
    }

    private function addMcqBody(\PhpOffice\PhpWord\Element\Section $section, Question $question, bool $teacher): void
    {
        foreach ($question->options as $option) {
            $section->addText(
                $option->option_label.'. '.$option->option_text,
                ['size' => 11],
            );
        }

        if (! $teacher) {
            return;
        }

        $correct = $question->options->firstWhere('is_correct', true);

        if ($correct !== null) {
            $section->addText('Kunci: '.$correct->option_label, ['bold' => true, 'size' => 11, 'color' => '1F4E79']);
        }

        if (is_string($question->explanation) && trim($question->explanation) !== '') {
            $section->addText('Pembahasan:', ['bold' => true, 'size' => 11]);
            $section->addText($question->explanation, ['size' => 11]);
        }
    }

    private function addTrueFalseBody(\PhpOffice\PhpWord\Element\Section $section, Question $question, bool $teacher): void
    {
        $section->addText('Benar', ['size' => 11]);
        $section->addText('Salah', ['size' => 11]);

        if (! $teacher) {
            return;
        }

        $correct = $question->options->firstWhere('is_correct', true);
        $key = $correct?->option_label === 'TRUE' ? 'Benar' : 'Salah';
        $section->addText('Kunci: '.$key, ['bold' => true, 'size' => 11, 'color' => '1F4E79']);

        if (is_string($question->explanation) && trim($question->explanation) !== '') {
            $section->addText('Pembahasan:', ['bold' => true, 'size' => 11]);
            $section->addText($question->explanation, ['size' => 11]);
        }
    }

    private function addEssayBody(\PhpOffice\PhpWord\Element\Section $section, Question $question, bool $teacher): void
    {
        if (! $teacher) {
            for ($line = 0; $line < self::ESSAY_ANSWER_LINES; $line++) {
                $section->addText(str_repeat('_', 72), ['size' => 11, 'color' => '98A2B3']);
            }

            return;
        }

        if (is_string($question->correct_answer) && trim($question->correct_answer) !== '') {
            $section->addText('Contoh jawaban:', ['bold' => true, 'size' => 11]);
            $section->addText($question->correct_answer, ['size' => 11]);
        }

        if (is_string($question->rubric) && trim($question->rubric) !== '') {
            $section->addText('Rubrik:', ['bold' => true, 'size' => 11]);
            $section->addText($question->rubric, ['size' => 11]);
        }

        if (is_string($question->explanation) && trim($question->explanation) !== '') {
            $section->addText('Pembahasan:', ['bold' => true, 'size' => 11]);
            $section->addText($question->explanation, ['size' => 11]);
        }
    }
}
