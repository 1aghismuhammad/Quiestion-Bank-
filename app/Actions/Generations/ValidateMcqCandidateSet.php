<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\McqValidationResult;
use App\Data\Generations\ValidatedMcqQuestion;

class ValidateMcqCandidateSet
{
    public function __construct(private DetectDuplicateMcqQuestions $duplicates) {}

    /**
     * @param  list<McqQuestionCandidate>  $candidates
     * @param  list<string>  $acceptedQuestionTexts
     */
    public function handle(array $candidates, array $acceptedQuestionTexts = []): McqValidationResult
    {
        $valid = [];
        $invalidReasons = [];
        $seenTexts = $acceptedQuestionTexts;

        foreach ($candidates as $candidate) {
            $validated = $this->validateOne($candidate);

            if ($validated === null) {
                $invalidReasons[] = 'invalid_candidate';

                continue;
            }

            if ($this->duplicates->isDuplicate($validated->question, $seenTexts)) {
                $invalidReasons[] = 'duplicate_question';

                continue;
            }

            $valid[] = $validated;
            $seenTexts[] = $validated->question;
        }

        return new McqValidationResult($valid, $invalidReasons);
    }

    private function validateOne(McqQuestionCandidate $candidate): ?ValidatedMcqQuestion
    {
        if (! is_string($candidate->question) || trim($candidate->question) === '') {
            return null;
        }

        if (! is_array($candidate->options)) {
            return null;
        }

        $keys = array_keys($candidate->options);
        sort($keys);

        if ($keys !== ['A', 'B', 'C', 'D']) {
            return null;
        }

        $options = [];
        $normalizedValues = [];

        foreach (['A', 'B', 'C', 'D'] as $key) {
            $value = $candidate->options[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            $trimmed = trim($value);
            $options[$key] = $trimmed;
            $normalizedValues[] = $this->duplicates->normalize($trimmed);
        }

        if (count(array_unique($normalizedValues)) !== 4) {
            return null;
        }

        if (! is_string($candidate->correctAnswer) || ! in_array($candidate->correctAnswer, ['A', 'B', 'C', 'D'], true)) {
            return null;
        }

        if (! is_string($candidate->explanation) || trim($candidate->explanation) === '') {
            return null;
        }

        return new ValidatedMcqQuestion(
            trim($candidate->question),
            $options,
            $candidate->correctAnswer,
            trim($candidate->explanation),
        );
    }
}
