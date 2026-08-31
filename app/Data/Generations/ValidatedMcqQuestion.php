<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class ValidatedMcqQuestion
{
    /**
     * @param  array{A: string, B: string, C: string, D: string}  $options
     */
    public function __construct(
        public string $question,
        public array $options,
        public string $correctAnswer,
        public string $explanation,
    ) {}

    /**
     * @return array{question: string, options: array{A: string, B: string, C: string, D: string}, correct_answer: string, explanation: string}
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'options' => $this->options,
            'correct_answer' => $this->correctAnswer,
            'explanation' => $this->explanation,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array{A: string, B: string, C: string, D: string} $options */
        $options = $payload['options'];

        return new self(
            (string) $payload['question'],
            $options,
            (string) $payload['correct_answer'],
            (string) $payload['explanation'],
        );
    }
}
