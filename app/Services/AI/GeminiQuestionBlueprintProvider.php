<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AI\QuestionBlueprintAnalysisProvider;
use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillRequest;
use App\Data\QuestionBlueprints\BlueprintFillResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Data\QuestionBlueprints\BlueprintProviderIdentity;
use App\Enums\BlueprintAttemptErrorCode;
use App\Exceptions\QuestionBlueprints\BlueprintMalformedResponseException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderPermanentException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderTransientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class GeminiQuestionBlueprintProvider implements QuestionBlueprintAnalysisProvider
{
    public const PROVIDER_NAME = 'google_gemini';

    public function __construct(private BlueprintFillPromptBuilder $promptBuilder) {}

    public function identity(): BlueprintProviderIdentity
    {
        return new BlueprintProviderIdentity(self::PROVIDER_NAME);
    }

    public function fillDraft(BlueprintFillRequest $request): BlueprintFillResult
    {
        $decoded = $this->call(
            $request->model,
            $this->promptBuilder->systemInstruction(),
            $this->promptBuilder->userPrompt($request),
            $this->promptBuilder->responseSchema(),
            $request->promptVersion,
        );

        $rows = $decoded['payload']['rows'] ?? null;

        if (! is_array($rows)) {
            throw new BlueprintMalformedResponseException('The blueprint provider JSON is missing rows.');
        }

        $candidates = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new BlueprintMalformedResponseException('The blueprint provider returned a non-object row.');
            }

            $contexts = [];

            foreach ($row['contexts'] ?? [] as $context) {
                if (! is_array($context)) {
                    throw new BlueprintMalformedResponseException('The blueprint provider returned a non-object context.');
                }

                $contexts[] = $context;
            }

            $candidates[] = new BlueprintFillCandidate(
                $row['objective'] ?? null,
                $row['topic'] ?? null,
                $row['indicator'] ?? null,
                $row['cognitive_level'] ?? null,
                $row['difficulty'] ?? null,
                $row['requested_count'] ?? null,
                $contexts,
            );
        }

        return new BlueprintFillResult($candidates, $decoded['metadata']);
    }

    /**
     * @param  array<string, mixed>  $responseSchema
     * @return array{payload: array<string, mixed>, metadata: BlueprintProviderAttemptMetadata}
     */
    private function call(
        string $model,
        string $systemInstruction,
        string $userPrompt,
        array $responseSchema,
        string $promptVersion,
    ): array {
        $apiKey = config('question_blueprint.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new BlueprintProviderPermanentException(
                BlueprintAttemptErrorCode::ProviderHttp,
                'The blueprint provider API key is not configured.',
            );
        }

        $url = rtrim((string) config('question_blueprint.api_base'), '/').'/models/'.$model.':generateContent';

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => (int) config('question_blueprint.max_output_tokens', 4096),
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ];

        $started = hrtime(true);

        try {
            $response = Http::timeout((int) config('question_blueprint.provider_http_timeout_seconds', 60))
                ->connectTimeout((int) config('question_blueprint.provider_connect_timeout_seconds', 10))
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($url, $payload);
        } catch (ConnectionException) {
            throw new BlueprintProviderTransientException(
                BlueprintAttemptErrorCode::ProviderTimeout,
                'The blueprint provider did not respond in time.',
            );
        }

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->throwForHttpStatus($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new BlueprintMalformedResponseException('The blueprint provider returned a non-JSON body.');
        }

        try {
            $decoded = json_decode($this->candidateText($body), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BlueprintMalformedResponseException('The blueprint provider returned invalid JSON.');
        }

        if (! is_array($decoded)) {
            throw new BlueprintMalformedResponseException('The blueprint provider returned invalid JSON.');
        }

        $usage = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];

        return [
            'payload' => $decoded,
            'metadata' => new BlueprintProviderAttemptMetadata(
                provider: self::PROVIDER_NAME,
                model: $model,
                promptVersion: $promptVersion,
                inputTokens: isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
                outputTokens: isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
                totalTokens: isset($usage['totalTokenCount']) ? (int) $usage['totalTokenCount'] : null,
                latencyMs: $latencyMs,
            ),
        ];
    }

    private function throwForHttpStatus(Response $response): void
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new BlueprintProviderPermanentException(
                BlueprintAttemptErrorCode::ProviderHttp,
                'The blueprint provider rejected the credentials.',
            );
        }

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new BlueprintProviderTransientException(
                BlueprintAttemptErrorCode::ProviderHttp,
                'The blueprint provider rate-limited the request.',
                is_numeric($retryAfter) ? min(30, max(0, (int) $retryAfter)) : null,
            );
        }

        if ($status >= 500) {
            throw new BlueprintProviderTransientException(
                BlueprintAttemptErrorCode::ProviderHttp,
                'The blueprint provider is unavailable.',
            );
        }

        if ($response->failed()) {
            throw new BlueprintProviderPermanentException(
                BlueprintAttemptErrorCode::ProviderHttp,
                'The blueprint provider rejected the request.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function candidateText(array $body): string
    {
        $candidates = $body['candidates'] ?? null;

        if (! is_array($candidates) || ! is_array($candidates[0] ?? null)) {
            throw new BlueprintMalformedResponseException('The blueprint provider returned no candidates.');
        }

        $parts = $candidates[0]['content']['parts'] ?? null;

        if (! is_array($parts)) {
            throw new BlueprintMalformedResponseException('The blueprint provider returned an empty candidate.');
        }

        $text = '';

        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);

        if ($text === '') {
            throw new BlueprintMalformedResponseException('The blueprint provider returned an empty candidate.');
        }

        return $text;
    }
}
