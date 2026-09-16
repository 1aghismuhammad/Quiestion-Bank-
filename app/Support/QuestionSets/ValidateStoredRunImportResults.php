<?php

declare(strict_types=1);

namespace App\Support\QuestionSets;

use App\Actions\Generations\ValidateEssayCandidateSet;
use App\Actions\Generations\ValidateMcqCandidateSet;
use App\Actions\Generations\ValidateTrueFalseCandidateSet;
use App\Data\Generations\EssayQuestionCandidate;
use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\TrueFalseQuestionCandidate;
use App\Data\Generations\ValidatedEssaySet;
use App\Data\Generations\ValidatedMcqSet;
use App\Data\Generations\ValidatedQuestionSet;
use App\Data\Generations\ValidatedTrueFalseSet;
use App\Enums\QuestionType;
use App\Exceptions\Generations\StoredQuestionSetReconstructionException;
use Illuminate\Validation\ValidationException;

final class ValidateStoredRunImportResults
{
    private const MESSAGE = 'Hasil generasi tidak valid untuk disimpan ke Question Bank.';

    public function __construct(
        private ValidateMcqCandidateSet $mcq,
        private ValidateTrueFalseCandidateSet $trueFalse,
        private ValidateEssayCandidateSet $essay,
    ) {}

    /**
     * @param  list<string>  $acceptedStems
     */
    public function validateChild(
        QuestionType $type,
        mixed $payload,
        int $requestedCount,
        array $acceptedStems,
    ): ValidatedQuestionSet {
        $this->assertListPayload($payload, $requestedCount);

        return match ($type) {
            QuestionType::MULTIPLE_CHOICE => $this->validateMcq($payload, $requestedCount, $acceptedStems),
            QuestionType::TRUE_FALSE => $this->validateTrueFalse($payload, $requestedCount, $acceptedStems),
            QuestionType::ESSAY => $this->validateEssay($payload, $requestedCount, $acceptedStems),
        };
    }

    private function assertListPayload(mixed $payload, int $requestedCount): void
    {
        if ($requestedCount < 1) {
            $this->reject();
        }

        if ($payload === null || $payload === []) {
            $this->reject();
        }

        if (! is_array($payload)) {
            $this->reject();
        }

        if (! array_is_list($payload)) {
            $this->reject();
        }

        if (count($payload) !== $requestedCount) {
            $this->reject();
        }

        foreach ($payload as $item) {
            if (! is_array($item)) {
                $this->reject();
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @param  list<string>  $acceptedStems
     */
    private function validateMcq(array $payload, int $requestedCount, array $acceptedStems): ValidatedMcqSet
    {
        $candidates = array_map(
            fn (array $item): McqQuestionCandidate => new McqQuestionCandidate(
                $item['question'] ?? null,
                $item['options'] ?? null,
                $item['correct_answer'] ?? null,
                $item['explanation'] ?? null,
            ),
            $payload,
        );

        $result = $this->mcq->handle($candidates, $acceptedStems);

        if (count($result->valid) !== $requestedCount || $result->invalidReasons !== []) {
            $this->reject();
        }

        return new ValidatedMcqSet($result->valid);
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @param  list<string>  $acceptedStems
     */
    private function validateTrueFalse(array $payload, int $requestedCount, array $acceptedStems): ValidatedTrueFalseSet
    {
        try {
            $stored = ValidatedTrueFalseSet::fromStoredJson($payload);
        } catch (StoredQuestionSetReconstructionException) {
            $this->reject();
        }

        $candidates = array_map(
            fn ($question): TrueFalseQuestionCandidate => new TrueFalseQuestionCandidate(
                $question->question,
                $question->correctAnswer,
                $question->explanation,
            ),
            $stored->items(),
        );

        $result = $this->trueFalse->handle($candidates, $acceptedStems, [], $requestedCount);

        if (count($result['valid']) !== $requestedCount || $result['invalidReasons'] !== []) {
            $this->reject();
        }

        return new ValidatedTrueFalseSet($result['valid']);
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @param  list<string>  $acceptedStems
     */
    private function validateEssay(array $payload, int $requestedCount, array $acceptedStems): ValidatedEssaySet
    {
        try {
            $stored = ValidatedEssaySet::fromStoredJson($payload);
        } catch (StoredQuestionSetReconstructionException) {
            $this->reject();
        }

        $candidates = array_map(
            fn ($question): EssayQuestionCandidate => new EssayQuestionCandidate(
                $question->question,
                $question->modelAnswer,
                $question->rubric,
                $question->explanation,
            ),
            $stored->items(),
        );

        $result = $this->essay->handle($candidates, $acceptedStems);

        if (count($result['valid']) !== $requestedCount || $result['invalidReasons'] !== []) {
            $this->reject();
        }

        return new ValidatedEssaySet($result['valid']);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'result' => self::MESSAGE,
        ]);
    }
}
