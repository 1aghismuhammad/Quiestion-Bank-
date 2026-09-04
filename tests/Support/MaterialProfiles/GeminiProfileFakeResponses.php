<?php

declare(strict_types=1);

namespace Tests\Support\MaterialProfiles;

final class GeminiProfileFakeResponses
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $usage
     * @return array<string, mixed>
     */
    public static function success(array $payload, array $usage = []): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => json_encode($payload, JSON_THROW_ON_ERROR)],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => $usage + [
                'promptTokenCount' => 120,
                'candidatesTokenCount' => 45,
                'totalTokenCount' => 165,
            ],
            'responseId' => 'fixture-response-id',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rawText(string $text): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    public static function mapPayload(array $observations): array
    {
        return ['observations' => $observations];
    }

    /**
     * @return array<string, mixed>
     */
    public static function observation(
        string $kind,
        string $text,
        string $excerpt,
        int $start,
        int $end,
    ): array {
        return [
            'kind' => $kind,
            'text' => $text,
            'evidence_excerpt' => $excerpt,
            'evidence_start' => $start,
            'evidence_end' => $end,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
    public static function reducePayload(array $elements): array
    {
        return ['elements' => $elements];
    }

    /**
     * @return array<string, mixed>
     */
    public static function element(string $kind, string $text): array
    {
        return ['kind' => $kind, 'text' => $text];
    }
}
