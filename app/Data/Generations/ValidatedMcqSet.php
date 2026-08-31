<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class ValidatedMcqSet
{
    /**
     * @param  list<ValidatedMcqQuestion>  $questions
     */
    public function __construct(public array $questions) {}

    public function count(): int
    {
        return count($this->questions);
    }

    /**
     * @return list<array{question: string, options: array{A: string, B: string, C: string, D: string}, correct_answer: string, explanation: string}>
     */
    public function toArray(): array
    {
        return array_map(
            fn (ValidatedMcqQuestion $question): array => $question->toArray(),
            $this->questions,
        );
    }

    /**
     * @return list<string>
     */
    public function questionTexts(): array
    {
        return array_map(
            fn (ValidatedMcqQuestion $question): string => $question->question,
            $this->questions,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    public static function fromStoredJson(array $payload): self
    {
        $questions = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                continue;
            }

            $questions[] = ValidatedMcqQuestion::fromArray($item);
        }

        return new self($questions);
    }
}
