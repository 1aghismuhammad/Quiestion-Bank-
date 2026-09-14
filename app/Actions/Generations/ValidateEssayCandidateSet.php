<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\Generations\EssayQuestionCandidate;
use App\Data\Generations\ValidatedEssayQuestion;

class ValidateEssayCandidateSet
{
    public function __construct(private DetectDuplicateMcqQuestions $duplicates) {}

    /**
     * @param  list<EssayQuestionCandidate>  $candidates
     * @param  list<string>  $acceptedQuestionTexts
     * @return array{valid: list<ValidatedEssayQuestion>, invalidReasons: list<string>}
     */
    public function handle(array $candidates, array $acceptedQuestionTexts): array
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

        return [
            'valid' => $valid,
            'invalidReasons' => $invalidReasons,
        ];
    }

    private function validateOne(EssayQuestionCandidate $candidate): ?ValidatedEssayQuestion
    {
        foreach (['question', 'modelAnswer', 'rubric', 'explanation'] as $field) {
            $value = $candidate->{$field};

            if (! is_string($value) || trim($value) === '') {
                return null;
            }
        }

        return new ValidatedEssayQuestion(
            trim((string) $candidate->question),
            trim((string) $candidate->modelAnswer),
            trim((string) $candidate->rubric),
            trim((string) $candidate->explanation),
        );
    }
}
