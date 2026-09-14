<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\Generations\TrueFalseQuestionCandidate;
use App\Data\Generations\ValidatedTrueFalseQuestion;
use App\Support\Generations\TrueFalseDistribution;

class ValidateTrueFalseCandidateSet
{
    public function __construct(private DetectDuplicateMcqQuestions $duplicates) {}

    /**
     * @param  list<TrueFalseQuestionCandidate>  $candidates
     * @param  list<string>  $acceptedQuestionTexts
     * @param  list<ValidatedTrueFalseQuestion>  $accepted
     * @return array{valid: list<ValidatedTrueFalseQuestion>, invalidReasons: list<string>}
     */
    public function handle(
        array $candidates,
        array $acceptedQuestionTexts,
        array $accepted,
        int $requestedCount,
    ): array {
        $valid = [];
        $invalidReasons = [];
        $seenTexts = $acceptedQuestionTexts;
        $trueCount = count(array_filter($accepted, fn (ValidatedTrueFalseQuestion $item): bool => $item->correctAnswer));
        $falseCount = count($accepted) - $trueCount;

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

            if (! TrueFalseDistribution::canAccept(
                $requestedCount,
                $trueCount,
                $falseCount,
                $validated->correctAnswer,
            )) {
                $invalidReasons[] = 'unbalanced_true_false';

                continue;
            }

            $valid[] = $validated;
            $seenTexts[] = $validated->question;

            if ($validated->correctAnswer) {
                $trueCount++;
            } else {
                $falseCount++;
            }
        }

        return [
            'valid' => $valid,
            'invalidReasons' => $invalidReasons,
        ];
    }

    private function validateOne(TrueFalseQuestionCandidate $candidate): ?ValidatedTrueFalseQuestion
    {
        if (! is_string($candidate->question) || trim($candidate->question) === '') {
            return null;
        }

        if (! is_bool($candidate->correctAnswer)) {
            return null;
        }

        if (! is_string($candidate->explanation) || trim($candidate->explanation) === '') {
            return null;
        }

        $question = trim($candidate->question);
        $explanation = trim($candidate->explanation);

        if ($this->isCompoundStatement($question) || $this->hasDoubleNegation($question)) {
            return null;
        }

        return new ValidatedTrueFalseQuestion($question, $candidate->correctAnswer, $explanation);
    }

    private function isCompoundStatement(string $question): bool
    {
        if (preg_match('/[.?!].*[.?!]/u', $question) === 1) {
            return true;
        }

        return str_contains($question, ';');
    }

    private function hasDoubleNegation(string $question): bool
    {
        $normalized = mb_strtolower($question, 'UTF-8');

        return str_contains($normalized, 'tidak tidak')
            || str_contains($normalized, 'not not')
            || str_contains($normalized, 'bukan tidak');
    }
}
