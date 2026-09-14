<?php

declare(strict_types=1);

namespace Tests\Unit\Generations;

use App\Data\Generations\ValidatedEssayQuestion;
use App\Data\Generations\ValidatedEssaySet;
use App\Data\Generations\ValidatedMcqSet;
use App\Data\Generations\ValidatedTrueFalseQuestion;
use App\Data\Generations\ValidatedTrueFalseSet;
use App\Enums\QuestionType;
use App\Exceptions\Generations\StoredQuestionSetReconstructionException;
use App\Support\Generations\ReconstructValidatedQuestionSet;
use App\Support\Generations\TrueFalseDistribution;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\TestCase;

class TypedStoredResultReconstructionTest extends TestCase
{
    public function test_malformed_stored_essay_data_fails_closed(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ReconstructValidatedQuestionSet::fromStored(QuestionType::ESSAY, 'not-an-array');
    }

    public function test_mcq_shaped_json_cannot_reconstruct_as_essay(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ReconstructValidatedQuestionSet::fromStored(
            QuestionType::ESSAY,
            [GeminiFakeResponses::question('MCQ stem')],
        );
    }

    public function test_true_false_shaped_json_cannot_reconstruct_as_essay(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ReconstructValidatedQuestionSet::fromStored(
            QuestionType::ESSAY,
            [GeminiFakeResponses::trueFalse('TF stem', true)],
        );
    }

    public function test_empty_or_non_string_essay_fields_fail_closed(): void
    {
        $valid = GeminiFakeResponses::essay('Valid essay');

        foreach ([
            array_merge($valid, ['question' => '']),
            array_merge($valid, ['model_answer' => '   ']),
            array_merge($valid, ['rubric' => 12]),
            array_merge($valid, ['explanation' => true]),
            array_merge($valid, ['question' => null]),
        ] as $payload) {
            try {
                ValidatedEssayQuestion::fromArray($payload);
                $this->fail('Malformed Essay fields must fail closed.');
            } catch (StoredQuestionSetReconstructionException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_malformed_stored_true_false_data_fails_closed(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ValidatedTrueFalseSet::fromStoredJson(['not-an-object']);
    }

    public function test_string_true_false_values_remain_rejected(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ValidatedTrueFalseQuestion::fromArray([
            'question' => 'Fotosintesis membutuhkan cahaya.',
            'correct_answer' => 'true',
            'explanation' => 'Karena materi.',
        ]);
    }

    public function test_non_array_true_false_entries_are_not_skipped(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ValidatedTrueFalseSet::fromStoredJson([
            GeminiFakeResponses::trueFalse('Valid statement', true),
            'skipped-if-lenient',
        ]);
    }

    public function test_non_array_essay_entries_are_not_skipped(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ValidatedEssaySet::fromStoredJson([
            GeminiFakeResponses::essay('Valid essay'),
            'skipped-if-lenient',
        ]);
    }

    public function test_extra_true_false_fields_fail_closed(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ValidatedTrueFalseQuestion::fromArray([
            'question' => 'Fotosintesis membutuhkan cahaya.',
            'correct_answer' => true,
            'explanation' => 'Karena materi.',
            'options' => ['A' => 'Benar', 'B' => 'Salah'],
        ]);
    }

    public function test_letter_correct_answer_cannot_substitute_for_essay(): void
    {
        $this->expectException(StoredQuestionSetReconstructionException::class);

        ValidatedEssayQuestion::fromArray([
            'question' => 'Jelaskan fotosintesis.',
            'model_answer' => 'Jawaban.',
            'rubric' => 'Rubrik.',
            'explanation' => 'Penjelasan.',
            'correct_answer' => 'A',
        ]);
    }

    public function test_unbalanced_complete_true_false_set_fails_closed(): void
    {
        $payload = [
            GeminiFakeResponses::trueFalse('One', true),
            GeminiFakeResponses::trueFalse('Two', true),
            GeminiFakeResponses::trueFalse('Three', true),
            GeminiFakeResponses::trueFalse('Four', false),
        ];

        $this->expectException(StoredQuestionSetReconstructionException::class);

        ReconstructValidatedQuestionSet::fromStored(QuestionType::TRUE_FALSE, $payload, 4);
    }

    public function test_infeasible_partial_true_false_distribution_fails_closed(): void
    {
        $payload = [
            GeminiFakeResponses::trueFalse('One', true),
            GeminiFakeResponses::trueFalse('Two', true),
            GeminiFakeResponses::trueFalse('Three', true),
        ];

        $this->expectException(StoredQuestionSetReconstructionException::class);

        ReconstructValidatedQuestionSet::fromStored(QuestionType::TRUE_FALSE, $payload, 4);
    }

    public function test_feasible_partial_true_false_set_remains_repairable(): void
    {
        $payload = [
            GeminiFakeResponses::trueFalse('One', true),
            GeminiFakeResponses::trueFalse('Two', true),
        ];

        $set = ReconstructValidatedQuestionSet::fromStored(QuestionType::TRUE_FALSE, $payload, 4);

        $this->assertInstanceOf(ValidatedTrueFalseSet::class, $set);
        $this->assertSame(2, $set->count());
        $this->assertSame(['true' => 0, 'false' => 2], TrueFalseDistribution::remaining(4, $set->trueCount(), $set->falseCount()));
    }

    public function test_balanced_complete_true_false_set_reconstructs(): void
    {
        $payload = GeminiFakeResponses::trueFalseQuestions(4, 'Balanced');
        $set = ReconstructValidatedQuestionSet::fromStored(QuestionType::TRUE_FALSE, $payload, 4);

        $this->assertSame(4, $set->count());
        $this->assertTrue(TrueFalseDistribution::isBalanced(4, 2, 2));
    }

    public function test_valid_essay_set_reconstructs_without_casting_numbers(): void
    {
        $payload = GeminiFakeResponses::essayQuestions(2, 'Essay');
        $set = ReconstructValidatedQuestionSet::fromStored(QuestionType::ESSAY, $payload);

        $this->assertSame(2, $set->count());
        $this->assertSame('Essay 1', $set->questionTexts()[0]);
    }

    public function test_historical_mcq_reconstruction_still_skips_non_array_entries(): void
    {
        $set = ValidatedMcqSet::fromStoredJson([
            GeminiFakeResponses::question('Keep this'),
            'ignore-me',
        ]);

        $this->assertSame(['Keep this'], $set->questionTexts());
    }
}
