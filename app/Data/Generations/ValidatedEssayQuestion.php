<?php

declare(strict_types=1);

namespace App\Data\Generations;

use App\Exceptions\Generations\StoredQuestionSetReconstructionException;

final readonly class ValidatedEssayQuestion
{
    public function __construct(
        public string $question,
        public string $modelAnswer,
        public string $rubric,
        public string $explanation,
    ) {}

    /**
     * @return array{question: string, model_answer: string, rubric: string, explanation: string}
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'model_answer' => $this->modelAnswer,
            'rubric' => $this->rubric,
            'explanation' => $this->explanation,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        self::assertExactKeys($payload, ['question', 'model_answer', 'rubric', 'explanation']);

        return new self(
            self::nonEmptyString($payload, 'question'),
            self::nonEmptyString($payload, 'model_answer'),
            self::nonEmptyString($payload, 'rubric'),
            self::nonEmptyString($payload, 'explanation'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function assertExactKeys(array $payload, array $keys): void
    {
        $actual = array_keys($payload);
        sort($actual);
        $expected = $keys;
        sort($expected);

        if ($actual !== $expected) {
            throw new StoredQuestionSetReconstructionException(
                'Stored Essay entry must contain exactly question, model_answer, rubric, and explanation.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function nonEmptyString(array $payload, string $key): string
    {
        if (! array_key_exists($key, $payload) || ! is_string($payload[$key])) {
            throw new StoredQuestionSetReconstructionException(
                'Stored Essay field must be a non-empty string.',
            );
        }

        $value = trim($payload[$key]);

        if ($value === '') {
            throw new StoredQuestionSetReconstructionException(
                'Stored Essay field must be a non-empty string.',
            );
        }

        return $value;
    }
}
