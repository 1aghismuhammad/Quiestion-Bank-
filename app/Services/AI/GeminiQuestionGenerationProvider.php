<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AI\QuestionGenerationProvider;
use App\Data\Generations\GenerationProviderRequest;
use App\Data\Generations\GenerationProviderResult;
use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\ProviderAttemptMetadata;
use App\Enums\GenerationErrorCode;
use App\Exceptions\Generations\GenerationConfigurationException;
use App\Exceptions\Generations\GenerationMalformedResponseException;
use App\Exceptions\Generations\GenerationProviderAuthException;
use App\Exceptions\Generations\GenerationProviderTransientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiQuestionGenerationProvider implements QuestionGenerationProvider
{
    public const PROVIDER_NAME = 'google_gemini';

    public function __construct(private McqPromptBuilder $promptBuilder) {}

    public function generate(GenerationProviderRequest $request): GenerationProviderResult
    {
        return $this->call($request);
    }

    public function repair(GenerationProviderRequest $request): GenerationProviderResult
    {
        return $this->call($request);
    }

    private function call(GenerationProviderRequest $request): GenerationProviderResult
    {
        $apiKey = config('generation.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new GenerationConfigurationException(
                'The generation API key is not configured.',
                $request->generationId,
            );
        }

        $model = $request->model;
        $url = rtrim((string) config('generation.api_base'), '/').'/models/'.$model.':generateContent';

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $this->promptBuilder->systemInstruction($request->outputLanguage)],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $this->promptBuilder->userPrompt($request)],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => (int) config('generation.max_output_tokens', 8192),
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->responseSchema(),
            ],
        ];

        $started = hrtime(true);

        try {
            $response = Http::timeout((int) config('generation.http_timeout', 60))
                ->connectTimeout((int) config('generation.http_connect_timeout', 10))
                ->acceptJson()
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new GenerationProviderTransientException(
                GenerationErrorCode::ProviderTimeout,
                'The generation provider timed out.',
                $request->generationId,
            );
        }

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->throwForHttpStatus($response, $request->generationId);

        $body = $response->json();

        if (! is_array($body)) {
            throw new GenerationMalformedResponseException(
                'The generation provider returned a non-JSON body.',
                $request->generationId,
            );
        }

        $text = $this->candidateText($body);

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GenerationMalformedResponseException(
                'The generation provider returned invalid JSON.',
                $request->generationId,
            );
        }

        if (! is_array($decoded)) {
            throw new GenerationMalformedResponseException(
                'The generation provider returned invalid JSON.',
                $request->generationId,
            );
        }

        $questions = $decoded['questions'] ?? null;

        if (! is_array($questions)) {
            throw new GenerationMalformedResponseException(
                'The generation provider JSON is missing questions.',
                $request->generationId,
            );
        }

        $candidates = [];

        foreach ($questions as $question) {
            if (! is_array($question)) {
                continue;
            }

            $candidates[] = new McqQuestionCandidate(
                $question['question'] ?? null,
                $question['options'] ?? null,
                $question['correct_answer'] ?? null,
                $question['explanation'] ?? null,
            );
        }

        $usage = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];

        return new GenerationProviderResult(
            $candidates,
            new ProviderAttemptMetadata(
                provider: self::PROVIDER_NAME,
                model: $model,
                inputTokens: isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
                outputTokens: isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
                totalTokens: isset($usage['totalTokenCount']) ? (int) $usage['totalTokenCount'] : null,
                latencyMs: $latencyMs,
                finishReason: $this->finishReason($body),
                safeRequestId: isset($body['responseId']) && is_string($body['responseId']) ? $body['responseId'] : null,
            ),
        );
    }

    private function throwForHttpStatus(Response $response, ?int $generationId): void
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new GenerationProviderAuthException(
                'The generation provider rejected the request.',
                $generationId,
            );
        }

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');
            $seconds = is_numeric($retryAfter) ? min(30, max(0, (int) $retryAfter)) : null;

            throw new GenerationProviderTransientException(
                GenerationErrorCode::ProviderRateLimited,
                'The generation provider rate-limited the request.',
                $generationId,
                $seconds,
            );
        }

        if ($status >= 500) {
            throw new GenerationProviderTransientException(
                GenerationErrorCode::ProviderUnavailable,
                'The generation provider is unavailable.',
                $generationId,
            );
        }

        if ($status === 404) {
            throw new GenerationConfigurationException(
                'The generation model is not available.',
                $generationId,
            );
        }

        if ($response->failed()) {
            throw new GenerationConfigurationException(
                'The generation provider rejected the request.',
                $generationId,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function candidateText(array $body): string
    {
        $candidates = $body['candidates'] ?? null;

        if (! is_array($candidates) || $candidates === []) {
            throw new GenerationMalformedResponseException(
                'The generation provider returned no candidates.',
            );
        }

        $first = $candidates[0];

        if (! is_array($first)) {
            throw new GenerationMalformedResponseException(
                'The generation provider returned no candidates.',
            );
        }

        $parts = $first['content']['parts'] ?? null;

        if (! is_array($parts)) {
            throw new GenerationMalformedResponseException(
                'The generation provider returned an empty candidate.',
            );
        }

        $text = '';

        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);

        if ($text === '') {
            throw new GenerationMalformedResponseException(
                'The generation provider returned an empty candidate.',
            );
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function finishReason(array $body): ?string
    {
        $candidates = $body['candidates'] ?? null;

        if (! is_array($candidates) || ! is_array($candidates[0] ?? null)) {
            return null;
        }

        $reason = $candidates[0]['finishReason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'options' => [
                                'type' => 'object',
                                'properties' => [
                                    'A' => ['type' => 'string'],
                                    'B' => ['type' => 'string'],
                                    'C' => ['type' => 'string'],
                                    'D' => ['type' => 'string'],
                                ],
                                'required' => ['A', 'B', 'C', 'D'],
                            ],
                            'correct_answer' => [
                                'type' => 'string',
                                'enum' => ['A', 'B', 'C', 'D'],
                            ],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['question', 'options', 'correct_answer', 'explanation'],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];
    }
}
