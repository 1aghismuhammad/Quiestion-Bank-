<?php

declare(strict_types=1);

namespace App\Data\Generations;

use App\Exceptions\Generations\StoredQuestionSetReconstructionException;

final readonly class ValidatedTrueFalseQuestion
{
    public function __construct(
        public string $question,
        public bool $correctAnswer,
        public string $explanation,
    ) {}

    /**
     * @return array{question: string, correct_answer: bool, explanation: string}
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'correct_answer' => $this->correctAnswer,
            'explanation' => $this->explanation,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        self::assertExactKeys($payload, ['question', 'correct_answer', 'explanation']);

        if (! array_key_exists('correct_answer', $payload) || ! is_bool($payload['correct_answer'])) {
            throw new StoredQuestionSetReconstructionException(
                'True/False correct_answer must be a JSON boolean.',
            );
        }

        return new self(
            self::nonEmptyString($payload, 'question'),
            $payload['correct_answer'],
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
                'Stored True/False entry must contain exactly question, correct_answer, and explanation.',
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
                'Stored True/False field must be a non-empty string.',
            );
        }

        $value = trim($payload[$key]);

        if ($value === '') {
            throw new StoredQuestionSetReconstructionException(
                'Stored True/False field must be a non-empty string.',
            );
        }

        return $value;
    }
}
