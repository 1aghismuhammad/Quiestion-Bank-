<?php

declare(strict_types=1);

namespace App\Actions\QuestionSets;

use App\Actions\Generations\ValidateEssayCandidateSet;
use App\Actions\Generations\ValidateMcqCandidateSet;
use App\Actions\Generations\ValidateTrueFalseCandidateSet;
use App\Data\Generations\EssayQuestionCandidate;
use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\TrueFalseQuestionCandidate;
use App\Data\Generations\ValidatedEssayQuestion;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Data\Generations\ValidatedTrueFalseQuestion;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionSet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InspectPersistedQuestionSet
{
    public const MCQ_LABELS = ['A', 'B', 'C', 'D'];

    public const TRUE_FALSE_LABELS = ['TRUE', 'FALSE'];

    public function __construct(
        private ValidateMcqCandidateSet $validateMcq,
        private ValidateTrueFalseCandidateSet $validateTrueFalse,
        private ValidateEssayCandidateSet $validateEssay,
    ) {}

    /**
     * @param  Collection<int, Question>  $questions
     */
    public function assertPublishable(QuestionSet $questionSet, Collection $questions): void
    {
        $count = $questions->count();

        if ($count < 1) {
            throw ValidationException::withMessages([
                'questions' => 'Question set harus memiliki minimal satu soal.',
            ]);
        }

        if ($count !== (int) $questionSet->total_question) {
            throw ValidationException::withMessages([
                'total_question' => 'Jumlah soal tersimpan tidak sesuai.',
            ]);
        }

        $numbers = $questions->pluck('question_number')->map(fn (mixed $number): int => (int) $number)->all();

        if ($numbers !== range(1, $count)) {
            throw ValidationException::withMessages([
                'questions' => 'Nomor soal tidak valid.',
            ]);
        }

        $this->assertEditableStructure($questions);
        $this->assertContentValid($questions);
    }

    /**
     * @param  Collection<int, Question>  $questions
     */
    public function assertEditableStructure(Collection $questions): void
    {
        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'questions' => 'Question set harus memiliki minimal satu soal.',
            ]);
        }

        foreach ($questions as $question) {
            match ($question->question_type) {
                QuestionType::MULTIPLE_CHOICE => $this->assertMcqStructure($question),
                QuestionType::TRUE_FALSE => $this->assertTrueFalseStructure($question),
                QuestionType::ESSAY => $this->assertEssayStructure($question),
                default => throw ValidationException::withMessages([
                    'question_type' => 'Tipe soal tidak didukung.',
                ]),
            };
        }
    }

    /**
     * @param  Collection<int, Question>  $questions
     */
    public function assertContentValid(Collection $questions): void
    {
        $stems = [];
        $trueFalseAccepted = [];
        $trueFalseCount = $questions->where('question_type', QuestionType::TRUE_FALSE)->count();

        foreach ($questions as $index => $question) {
            $one = match ($question->question_type) {
                QuestionType::MULTIPLE_CHOICE => $this->validateMcq($question, $stems),
                QuestionType::TRUE_FALSE => $this->validateTrueFalse($question, $stems, $trueFalseAccepted, $trueFalseCount),
                QuestionType::ESSAY => $this->validateEssay($question, $stems),
                default => null,
            };

            if ($one === null) {
                throw ValidationException::withMessages([
                    "questions.{$index}.question_text" => 'Soal tidak valid untuk diterbitkan.',
                ]);
            }

            $stems[] = $one instanceof ValidatedMcqQuestion || $one instanceof ValidatedTrueFalseQuestion || $one instanceof ValidatedEssayQuestion
                ? $one->question
                : '';

            if ($one instanceof ValidatedTrueFalseQuestion) {
                $trueFalseAccepted[] = $one;
            }
        }
    }

    private function assertMcqStructure(Question $question): void
    {
        if ($question->correct_answer !== null || $question->rubric !== null) {
            throw ValidationException::withMessages([
                'questions' => 'Struktur soal pilihan ganda tidak valid.',
            ]);
        }

        $this->mcqOptionMap($question);
    }

    private function assertTrueFalseStructure(Question $question): void
    {
        if ($question->correct_answer !== null || $question->rubric !== null) {
            throw ValidationException::withMessages([
                'questions' => 'Struktur soal benar/salah tidak valid.',
            ]);
        }

        $this->trueFalseOptionMap($question);
    }

    private function assertEssayStructure(Question $question): void
    {
        if ($question->options->isNotEmpty()) {
            throw ValidationException::withMessages([
                'questions' => 'Soal esai tidak boleh memiliki opsi.',
            ]);
        }

        if (! is_string($question->correct_answer) || trim($question->correct_answer) === '') {
            throw ValidationException::withMessages([
                'questions' => 'Contoh jawaban esai wajib diisi.',
            ]);
        }

        if (! is_string($question->rubric) || trim($question->rubric) === '') {
            throw ValidationException::withMessages([
                'questions' => 'Rubrik esai wajib diisi.',
            ]);
        }

        if (! is_string($question->explanation) || trim($question->explanation) === '') {
            throw ValidationException::withMessages([
                'questions' => 'Penjelasan esai wajib diisi.',
            ]);
        }

        if (! is_string($question->question_text) || trim($question->question_text) === '') {
            throw ValidationException::withMessages([
                'questions' => 'Teks soal esai wajib diisi.',
            ]);
        }
    }

    /**
     * @return array{0: array{A: string, B: string, C: string, D: string}, 1: string}
     */
    public function mcqOptionMap(Question $question): array
    {
        $options = [];
        $correct = null;
        $correctCount = 0;

        foreach ($question->options as $option) {
            $label = $option->option_label;
            $options[$label] = $option->option_text;

            if ($option->is_correct) {
                $correctCount++;
                $correct = $label;
            }
        }

        $keys = array_keys($options);
        sort($keys);

        if ($keys !== self::MCQ_LABELS || $correctCount !== 1 || ! is_string($correct)) {
            throw ValidationException::withMessages([
                'questions' => 'Struktur opsi soal tidak valid.',
            ]);
        }

        return [
            [
                'A' => (string) $options['A'],
                'B' => (string) $options['B'],
                'C' => (string) $options['C'],
                'D' => (string) $options['D'],
            ],
            $correct,
        ];
    }

    /**
     * @return array{0: array{TRUE: string, FALSE: string}, 1: bool}
     */
    public function trueFalseOptionMap(Question $question): array
    {
        $byLabel = [];
        $correctCount = 0;
        $correctIsTrue = null;

        foreach ($question->options->sortBy('sort_order')->values() as $index => $option) {
            $label = $option->option_label;
            $byLabel[$label] = $option;

            if ($option->is_correct) {
                $correctCount++;
                $correctIsTrue = $label === 'TRUE';
            }

            $expectedLabel = self::TRUE_FALSE_LABELS[$index] ?? null;
            $expectedText = $label === 'TRUE' ? 'Benar' : ($label === 'FALSE' ? 'Salah' : null);

            if ($expectedLabel === null || $label !== $expectedLabel || $option->option_text !== $expectedText) {
                throw ValidationException::withMessages([
                    'questions' => 'Struktur opsi benar/salah tidak valid.',
                ]);
            }

            if ((int) $option->sort_order !== $index + 1) {
                throw ValidationException::withMessages([
                    'questions' => 'Struktur opsi benar/salah tidak valid.',
                ]);
            }
        }

        $keys = array_keys($byLabel);
        sort($keys);
        $expected = self::TRUE_FALSE_LABELS;
        sort($expected);

        if ($keys !== $expected || $correctCount !== 1 || ! is_bool($correctIsTrue) || $question->options->count() !== 2) {
            throw ValidationException::withMessages([
                'questions' => 'Struktur opsi benar/salah tidak valid.',
            ]);
        }

        return [
            [
                'TRUE' => 'Benar',
                'FALSE' => 'Salah',
            ],
            $correctIsTrue,
        ];
    }

    /**
     * @param  list<string>  $stems
     */
    private function validateMcq(Question $question, array $stems): ?ValidatedMcqQuestion
    {
        [$options, $correct] = $this->mcqOptionMap($question);
        $result = $this->validateMcq->handle([
            new McqQuestionCandidate($question->question_text, $options, $correct, $question->explanation),
        ], $stems);

        return $result->validCount() === 1 && $result->invalidReasons === []
            ? $result->valid[0]
            : null;
    }

    /**
     * @param  list<string>  $stems
     * @param  list<ValidatedTrueFalseQuestion>  $accepted
     */
    private function validateTrueFalse(
        Question $question,
        array $stems,
        array $accepted,
        int $requestedCount,
    ): ?ValidatedTrueFalseQuestion {
        [, $correct] = $this->trueFalseOptionMap($question);
        $result = $this->validateTrueFalse->handle(
            [new TrueFalseQuestionCandidate($question->question_text, $correct, $question->explanation)],
            $stems,
            $accepted,
            max(1, $requestedCount),
        );

        return ($result['valid'][0] ?? null) instanceof ValidatedTrueFalseQuestion
            && $result['invalidReasons'] === []
            ? $result['valid'][0]
            : null;
    }

    /**
     * @param  list<string>  $stems
     */
    private function validateEssay(Question $question, array $stems): ?ValidatedEssayQuestion
    {
        $result = $this->validateEssay->handle([
            new EssayQuestionCandidate(
                $question->question_text,
                $question->correct_answer,
                $question->rubric,
                $question->explanation,
            ),
        ], $stems);

        return ($result['valid'][0] ?? null) instanceof ValidatedEssayQuestion
            && $result['invalidReasons'] === []
            ? $result['valid'][0]
            : null;
    }
}
