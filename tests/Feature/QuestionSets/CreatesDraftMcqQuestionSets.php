<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewStatus;
use App\Enums\Visibility;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\User;

trait CreatesDraftMcqQuestionSets
{
    /**
     * @param  array<string, mixed>  $setAttributes
     */
    protected function draftMcqSet(User $owner, int $count = 2, array $setAttributes = []): QuestionSet
    {
        $set = QuestionSet::factory()->for($owner)->create(array_merge([
            'title' => 'Draft bank',
            'status' => QuestionSetStatus::DRAFT,
            'visibility' => Visibility::PRIVATE,
            'review_status' => ReviewStatus::NOT_SUBMITTED,
            'total_question' => $count,
        ], $setAttributes));

        for ($number = 1; $number <= $count; $number++) {
            $this->addMcqQuestion($set, $number, 'Stem '.$number);
        }

        return $set->load('questions.options');
    }

    protected function addMcqQuestion(QuestionSet $set, int $number, string $stem, string $correct = 'A'): Question
    {
        $question = Question::factory()->for($set, 'questionSet')->create([
            'question_number' => $number,
            'question_text' => $stem,
            'question_type' => QuestionType::MULTIPLE_CHOICE,
            'explanation' => 'Because the material supports '.$stem,
            'correct_answer' => null,
        ]);

        foreach (['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4] as $label => $order) {
            QuestionOption::factory()->for($question)->create([
                'option_label' => $label,
                'option_text' => 'Option '.$label.' for '.$stem,
                'sort_order' => $order,
                'is_correct' => $label === $correct,
            ]);
        }

        return $question->load('options');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{title: string, questions: list<array<string, mixed>>}
     */
    protected function updatePayload(QuestionSet $set, array $overrides = []): array
    {
        return $this->typedUpdatePayload($set, $overrides);
    }

    protected function addTrueFalseQuestion(
        QuestionSet $set,
        int $number,
        string $stem,
        bool $correct = true,
    ): Question {
        $question = Question::factory()->for($set, 'questionSet')->create([
            'question_number' => $number,
            'question_text' => $stem,
            'question_type' => QuestionType::TRUE_FALSE,
            'explanation' => 'Because the material supports '.$stem,
            'correct_answer' => null,
            'rubric' => null,
        ]);

        QuestionOption::factory()->for($question)->create([
            'option_label' => 'TRUE',
            'option_text' => 'Benar',
            'sort_order' => 1,
            'is_correct' => $correct,
        ]);
        QuestionOption::factory()->for($question)->create([
            'option_label' => 'FALSE',
            'option_text' => 'Salah',
            'sort_order' => 2,
            'is_correct' => ! $correct,
        ]);

        return $question->load('options');
    }

    protected function addEssayQuestion(
        QuestionSet $set,
        int $number,
        string $stem,
        string $modelAnswer = 'Model answer',
        string $rubric = 'Required elements; full credit; partial credit; insufficient or incorrect.',
    ): Question {
        return Question::factory()->for($set, 'questionSet')->create([
            'question_number' => $number,
            'question_text' => $stem,
            'question_type' => QuestionType::ESSAY,
            'explanation' => 'Because the material supports '.$stem,
            'correct_answer' => $modelAnswer,
            'rubric' => $rubric,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setAttributes
     */
    protected function draftTrueFalseSet(User $owner, int $count = 2, array $setAttributes = []): QuestionSet
    {
        $set = QuestionSet::factory()->for($owner)->create(array_merge([
            'title' => 'Draft TF bank',
            'status' => QuestionSetStatus::DRAFT,
            'visibility' => Visibility::PRIVATE,
            'review_status' => ReviewStatus::NOT_SUBMITTED,
            'total_question' => $count,
        ], $setAttributes));

        for ($number = 1; $number <= $count; $number++) {
            $this->addTrueFalseQuestion($set, $number, 'TF stem '.$number, $number % 2 === 1);
        }

        return $set->load('questions.options');
    }

    /**
     * @param  array<string, mixed>  $setAttributes
     */
    protected function draftEssaySet(User $owner, int $count = 1, array $setAttributes = []): QuestionSet
    {
        $set = QuestionSet::factory()->for($owner)->create(array_merge([
            'title' => 'Draft essay bank',
            'status' => QuestionSetStatus::DRAFT,
            'visibility' => Visibility::PRIVATE,
            'review_status' => ReviewStatus::NOT_SUBMITTED,
            'total_question' => $count,
        ], $setAttributes));

        for ($number = 1; $number <= $count; $number++) {
            $this->addEssayQuestion($set, $number, 'Essay stem '.$number);
        }

        return $set->load('questions.options');
    }

    /**
     * @param  array<string, mixed>  $setAttributes
     */
    protected function draftMixedSet(User $owner, array $setAttributes = []): QuestionSet
    {
        $set = QuestionSet::factory()->for($owner)->create(array_merge([
            'title' => 'Draft mixed bank',
            'status' => QuestionSetStatus::DRAFT,
            'visibility' => Visibility::PRIVATE,
            'review_status' => ReviewStatus::NOT_SUBMITTED,
            'total_question' => 3,
        ], $setAttributes));

        $this->addMcqQuestion($set, 1, 'Mixed MCQ');
        $this->addTrueFalseQuestion($set, 2, 'Mixed TF', true);
        $this->addEssayQuestion($set, 3, 'Mixed Essay');

        return $set->load('questions.options');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{title: string, questions: list<array<string, mixed>>}
     */
    protected function typedUpdatePayload(QuestionSet $set, array $overrides = []): array
    {
        $set->loadMissing('questions.options');
        $questionOverrides = $overrides['questions'] ?? [];
        unset($overrides['questions']);

        $questions = [];

        foreach ($set->questions as $index => $question) {
            $questions[] = array_merge(
                ['question_id' => $question->question_id],
                match ($question->question_type) {
                    QuestionType::MULTIPLE_CHOICE => $this->mcqPayloadRow($question),
                    QuestionType::TRUE_FALSE => $this->trueFalsePayloadRow($question),
                    QuestionType::ESSAY => $this->essayPayloadRow($question),
                    default => [
                        'question_text' => $question->question_text,
                        'explanation' => $question->explanation,
                    ],
                },
                $questionOverrides[$index] ?? [],
            );
        }

        return array_merge([
            'title' => $set->title,
            'questions' => $questions,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function mcqPayloadRow(Question $question): array
    {
        $options = [];

        foreach ($question->options as $option) {
            $options[$option->option_label] = $option->option_text;
        }

        $correct = $question->options->firstWhere('is_correct', true)?->option_label ?? 'A';

        return [
            'question_text' => $question->question_text,
            'options' => $options,
            'correct_answer' => $correct,
            'explanation' => $question->explanation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trueFalsePayloadRow(Question $question): array
    {
        $correct = $question->options->firstWhere('is_correct', true)?->option_label === 'TRUE'
            ? 'Benar'
            : 'Salah';

        return [
            'question_text' => $question->question_text,
            'correct_answer' => $correct,
            'explanation' => $question->explanation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function essayPayloadRow(Question $question): array
    {
        return [
            'question_text' => $question->question_text,
            'model_answer' => $question->correct_answer,
            'rubric' => $question->rubric,
            'explanation' => $question->explanation,
        ];
    }
}
