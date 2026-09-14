<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\Generations\EssayQuestionCandidate;
use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\TrueFalseQuestionCandidate;
use App\Data\Generations\ValidatedQuestionSet;
use App\Data\Generations\ValidatedTrueFalseSet;
use App\Enums\QuestionType;

class ValidateTypedGenerationCandidates
{
    public function __construct(
        private ValidateMcqCandidateSet $mcq,
        private ValidateTrueFalseCandidateSet $trueFalse,
        private ValidateEssayCandidateSet $essay,
    ) {}

    /**
     * @param  list<McqQuestionCandidate|TrueFalseQuestionCandidate|EssayQuestionCandidate>  $candidates
     * @param  list<string>  $acceptedQuestionTexts
     * @return list<object>
     */
    public function handle(
        QuestionType $type,
        array $candidates,
        array $acceptedQuestionTexts,
        ValidatedQuestionSet $accepted,
        int $requestedCount,
    ): array {
        return match ($type) {
            QuestionType::MULTIPLE_CHOICE => $this->mcq->handle(
                array_values(array_filter(
                    $candidates,
                    fn (mixed $candidate): bool => $candidate instanceof McqQuestionCandidate,
                )),
                $acceptedQuestionTexts,
            )->valid,
            QuestionType::TRUE_FALSE => $this->trueFalse->handle(
                array_values(array_filter(
                    $candidates,
                    fn (mixed $candidate): bool => $candidate instanceof TrueFalseQuestionCandidate,
                )),
                $acceptedQuestionTexts,
                $accepted instanceof ValidatedTrueFalseSet ? $accepted->questions : [],
                $requestedCount,
            )['valid'],
            QuestionType::ESSAY => $this->essay->handle(
                array_values(array_filter(
                    $candidates,
                    fn (mixed $candidate): bool => $candidate instanceof EssayQuestionCandidate,
                )),
                $acceptedQuestionTexts,
            )['valid'],
        };
    }
}
