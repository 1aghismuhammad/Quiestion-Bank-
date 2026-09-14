<?php

declare(strict_types=1);

namespace Tests\Support\Generations;

final class GeminiFakeResponses
{
    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, int>  $usage
     * @return array<string, mixed>
     */
    public static function success(array $questions, array $usage = []): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => json_encode(['questions' => $questions], JSON_THROW_ON_ERROR)],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => $usage === [] ? [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 20,
                'totalTokenCount' => 30,
            ] : $usage,
        ];
    }

    /**
     * @return array{question: string, options: array{A: string, B: string, C: string, D: string}, correct_answer: string, explanation: string}
     */
    public static function question(string $stem, string $answer = 'A'): array
    {
        return [
            'question' => $stem,
            'options' => [
                'A' => 'Option A for '.$stem,
                'B' => 'Option B for '.$stem,
                'C' => 'Option C for '.$stem,
                'D' => 'Option D for '.$stem,
            ],
            'correct_answer' => $answer,
            'explanation' => 'Because the material supports '.$stem,
        ];
    }

    /**
     * @return list<array{question: string, options: array{A: string, B: string, C: string, D: string}, correct_answer: string, explanation: string}>
     */
    public static function questions(int $count, string $prefix = 'Question'): array
    {
        $items = [];

        for ($i = 1; $i <= $count; $i++) {
            $items[] = self::question($prefix.' '.$i);
        }

        return $items;
    }

    /**
     * @return array{question: string, correct_answer: bool, explanation: string}
     */
    public static function trueFalse(string $stem, bool $answer = true): array
    {
        return [
            'question' => $stem,
            'correct_answer' => $answer,
            'explanation' => 'Because the material supports '.$stem,
        ];
    }

    /**
     * @return list<array{question: string, correct_answer: bool, explanation: string}>
     */
    public static function trueFalseQuestions(int $count, string $prefix = 'Statement'): array
    {
        $items = [];

        for ($i = 1; $i <= $count; $i++) {
            $items[] = self::trueFalse($prefix.' '.$i, $i % 2 === 1);
        }

        return $items;
    }

    /**
     * @return array{question: string, model_answer: string, rubric: string, explanation: string}
     */
    public static function essay(string $stem): array
    {
        return [
            'question' => $stem,
            'model_answer' => 'Model answer for '.$stem,
            'rubric' => 'Required elements; full credit; partial credit; insufficient or incorrect.',
            'explanation' => 'Because the material supports '.$stem,
        ];
    }

    /**
     * @return list<array{question: string, model_answer: string, rubric: string, explanation: string}>
     */
    public static function essayQuestions(int $count, string $prefix = 'Essay'): array
    {
        $items = [];

        for ($i = 1; $i <= $count; $i++) {
            $items[] = self::essay($prefix.' '.$i);
        }

        return $items;
    }
}
