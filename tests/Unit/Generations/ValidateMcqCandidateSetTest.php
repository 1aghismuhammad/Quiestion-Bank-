<?php

declare(strict_types=1);

namespace Tests\Unit\Generations;

use App\Actions\Generations\DetectDuplicateMcqQuestions;
use App\Actions\Generations\ValidateMcqCandidateSet;
use App\Data\Generations\McqQuestionCandidate;
use Tests\TestCase;

class ValidateMcqCandidateSetTest extends TestCase
{
    public function test_accepts_a_valid_mcq(): void
    {
        $result = $this->validator()->handle([
            $this->candidate('What is 2+2?'),
        ]);

        $this->assertSame(1, $result->validCount());
        $this->assertSame('What is 2+2?', $result->valid[0]->question);
        $this->assertSame('A', $result->valid[0]->correctAnswer);
    }

    public function test_rejects_missing_option_key_and_empty_explanation(): void
    {
        $badOptions = new McqQuestionCandidate(
            'Stem',
            ['A' => 'a', 'B' => 'b', 'C' => 'c'],
            'A',
            'Because',
        );
        $emptyExplanation = new McqQuestionCandidate(
            'Stem 2',
            ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
            'A',
            '   ',
        );

        $result = $this->validator()->handle([$badOptions, $emptyExplanation]);

        $this->assertSame(0, $result->validCount());
        $this->assertCount(2, $result->invalidReasons);
    }

    public function test_rejects_identical_options_and_invalid_correct_answer(): void
    {
        $identical = new McqQuestionCandidate(
            'Stem',
            ['A' => 'same', 'B' => 'same', 'C' => 'same', 'D' => 'same'],
            'A',
            'Because',
        );
        $badAnswer = new McqQuestionCandidate(
            'Stem 2',
            ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
            'E',
            'Because',
        );

        $result = $this->validator()->handle([$identical, $badAnswer]);

        $this->assertSame(0, $result->validCount());
    }

    public function test_rejects_when_two_options_share_the_same_normalized_text(): void
    {
        $partiallyDuplicate = new McqQuestionCandidate(
            'Stem',
            ['A' => 'same', 'B' => 'same', 'C' => 'three', 'D' => 'four'],
            'A',
            'Because',
        );

        $result = $this->validator()->handle([$partiallyDuplicate]);

        $this->assertSame(0, $result->validCount());
        $this->assertCount(1, $result->invalidReasons);
    }

    public function test_rejects_duplicate_within_set_and_against_accepted(): void
    {
        $first = $this->candidate('What is photosynthesis?');
        $duplicate = $this->candidate('  WHAT IS PHOTOSYNTHESIS?  ');
        $result = $this->validator()->handle([$first, $duplicate], ['What is photosynthesis?']);

        $this->assertSame(0, $result->validCount());
    }

    public function test_normalizer_collapses_whitespace_and_punctuation(): void
    {
        $duplicates = $this->app->make(DetectDuplicateMcqQuestions::class);

        $this->assertTrue($duplicates->isDuplicate('Hello world!', ['hello world']));
        $this->assertFalse($duplicates->isDuplicate('Hello there', ['hello world']));
    }

    private function validator(): ValidateMcqCandidateSet
    {
        return $this->app->make(ValidateMcqCandidateSet::class);
    }

    private function candidate(string $question): McqQuestionCandidate
    {
        return new McqQuestionCandidate(
            $question,
            ['A' => 'one', 'B' => 'two', 'C' => 'three', 'D' => 'four'],
            'A',
            'Because the material says so.',
        );
    }
}
