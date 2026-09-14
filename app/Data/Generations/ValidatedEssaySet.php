<?php

declare(strict_types=1);

namespace App\Data\Generations;

use App\Exceptions\Generations\StoredQuestionSetReconstructionException;

final readonly class ValidatedEssaySet implements ValidatedQuestionSet
{
    /**
     * @param  list<ValidatedEssayQuestion>  $questions
     */
    public function __construct(public array $questions) {}

    public function count(): int
    {
        return count($this->questions);
    }

    /**
     * @return list<array{question: string, model_answer: string, rubric: string, explanation: string}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (ValidatedEssayQuestion $question): array => $question->toArray(),
            $this->questions,
        );
    }

    /**
     * @return list<string>
     */
    public function questionTexts(): array
    {
        return array_map(
            fn (ValidatedEssayQuestion $question): string => $question->question,
            $this->questions,
        );
    }

    /**
     * @return list<ValidatedEssayQuestion>
     */
    public function items(): array
    {
        return $this->questions;
    }

    /**
     * @param  list<mixed>  $payload
     */
    public static function fromStoredJson(array $payload): self
    {
        if (! array_is_list($payload)) {
            throw new StoredQuestionSetReconstructionException(
                'Stored Essay payload must be a list.',
            );
        }

        $questions = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                throw new StoredQuestionSetReconstructionException(
                    'Stored Essay entry must be an object.',
                );
            }

            $questions[] = ValidatedEssayQuestion::fromArray($item);
        }

        return new self($questions);
    }
}
