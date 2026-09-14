<?php

declare(strict_types=1);

namespace App\Data\Generations;

use App\Exceptions\Generations\StoredQuestionSetReconstructionException;

final readonly class ValidatedTrueFalseSet implements ValidatedQuestionSet
{
    /**
     * @param  list<ValidatedTrueFalseQuestion>  $questions
     */
    public function __construct(public array $questions) {}

    public function count(): int
    {
        return count($this->questions);
    }

    /**
     * @return list<array{question: string, correct_answer: bool, explanation: string}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (ValidatedTrueFalseQuestion $question): array => $question->toArray(),
            $this->questions,
        );
    }

    /**
     * @return list<string>
     */
    public function questionTexts(): array
    {
        return array_map(
            fn (ValidatedTrueFalseQuestion $question): string => $question->question,
            $this->questions,
        );
    }

    /**
     * @return list<ValidatedTrueFalseQuestion>
     */
    public function items(): array
    {
        return $this->questions;
    }

    public function trueCount(): int
    {
        return count(array_filter(
            $this->questions,
            fn (ValidatedTrueFalseQuestion $question): bool => $question->correctAnswer,
        ));
    }

    public function falseCount(): int
    {
        return $this->count() - $this->trueCount();
    }

    /**
     * @param  list<mixed>  $payload
     */
    public static function fromStoredJson(array $payload): self
    {
        if (! array_is_list($payload)) {
            throw new StoredQuestionSetReconstructionException(
                'Stored True/False payload must be a list.',
            );
        }

        $questions = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                throw new StoredQuestionSetReconstructionException(
                    'Stored True/False entry must be an object.',
                );
            }

            $questions[] = ValidatedTrueFalseQuestion::fromArray($item);
        }

        return new self($questions);
    }
}
