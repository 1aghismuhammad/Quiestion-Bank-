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
        $set->loadMissing('questions.options');
        $questionOverrides = $overrides['questions'] ?? [];
        unset($overrides['questions']);

        $questions = [];

        foreach ($set->questions as $index => $question) {
            $options = [];

            foreach ($question->options as $option) {
                $options[$option->option_label] = $option->option_text;
            }

            $correct = $question->options->firstWhere('is_correct', true)?->option_label ?? 'A';

            $questions[] = array_merge([
                'question_id' => $question->question_id,
                'question_text' => $question->question_text,
                'options' => $options,
                'correct_answer' => $correct,
                'explanation' => $question->explanation,
            ], $questionOverrides[$index] ?? []);
        }

        return array_merge([
            'title' => $set->title,
            'questions' => $questions,
        ], $overrides);
    }
}
