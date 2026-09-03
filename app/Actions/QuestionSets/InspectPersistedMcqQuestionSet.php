<?php

declare(strict_types=1);

namespace App\Actions\QuestionSets;

use App\Data\Generations\McqQuestionCandidate;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionSet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InspectPersistedMcqQuestionSet
{
    public const LABELS = ['A', 'B', 'C', 'D'];

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

        foreach ($questions as $question) {
            if ($question->question_type !== QuestionType::MULTIPLE_CHOICE) {
                throw ValidationException::withMessages([
                    'question_type' => 'Hanya soal pilihan ganda yang dapat diterbitkan.',
                ]);
            }
        }

        $this->assertEditableStructure($questions);
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
            if ($question->question_type !== QuestionType::MULTIPLE_CHOICE) {
                throw ValidationException::withMessages([
                    'question_type' => 'Hanya soal pilihan ganda yang dapat diedit.',
                ]);
            }

            $this->optionMap($question);
        }
    }

    /**
     * @param  Collection<int, Question>  $questions
     * @return list<McqQuestionCandidate>
     */
    public function candidates(Collection $questions): array
    {
        $candidates = [];

        foreach ($questions as $question) {
            [$options, $correct] = $this->optionMap($question);

            $candidates[] = new McqQuestionCandidate(
                $question->question_text,
                $options,
                $correct,
                $question->explanation,
            );
        }

        return $candidates;
    }

    /**
     * @return array{0: array{A: string, B: string, C: string, D: string}, 1: string}
     */
    private function optionMap(Question $question): array
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

        if ($keys !== self::LABELS || $correctCount !== 1 || ! is_string($correct)) {
            throw ValidationException::withMessages([
                'questions' => 'Struktur opsi soal tidak valid.',
            ]);
        }

        return [
            [
                'A' => $options['A'],
                'B' => $options['B'],
                'C' => $options['C'],
                'D' => $options['D'],
            ],
            $correct,
        ];
    }
}
