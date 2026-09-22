<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AI\QuestionBlueprintImportInterpretationProvider;
use App\Data\QuestionBlueprints\BlueprintImportProviderInterpretation;
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

class GeminiQuestionBlueprintImportProvider implements QuestionBlueprintImportInterpretationProvider
{
    public const PROVIDER_NAME = 'google_gemini';

    public function __construct(private BlueprintImportInterpretationPromptBuilder $promptBuilder) {}

    public function identity(): BlueprintProviderIdentity
    {
        return new BlueprintProviderIdentity(self::PROVIDER_NAME);
    }

    public function interpret(string $serializedStructure, string $promptVersion, string $model): BlueprintImportProviderInterpretation
    {
        $decoded = $this->call(
            $model,
            $this->promptBuilder->systemInstruction(),
            $this->promptBuilder->userPrompt($serializedStructure),
            $this->promptBuilder->responseSchema(),
            $promptVersion,
        );

        $payload = $decoded['payload'];

        foreach (['document_kind', 'candidates', 'warnings'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new BlueprintMalformedResponseException('The interpretation provider JSON is missing '.$key.'.');
            }
        }

        $kind = $payload['document_kind'];
        $candidates = $payload['candidates'];
        $warnings = $payload['warnings'];

        if (! is_string($kind) || $kind === '') {
            throw new BlueprintMalformedResponseException('The interpretation provider JSON is missing document_kind.');
        }

        if (! is_array($candidates) || ! array_is_list($candidates)) {
            throw new BlueprintMalformedResponseException('The interpretation provider JSON is missing candidates.');
        }

        if (! is_array($warnings) || ! array_is_list($warnings)) {
            throw new BlueprintMalformedResponseException('The interpretation provider JSON is missing warnings.');
        }

        $normalized = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned a non-object candidate.');
            }

            foreach (['bindings', 'warnings', 'unresolved'] as $key) {
                if (! array_key_exists($key, $candidate)) {
                    throw new BlueprintMalformedResponseException('The interpretation provider returned a candidate missing '.$key.'.');
                }
            }

            $bindings = $candidate['bindings'];
            $candidateWarnings = $candidate['warnings'];
            $unresolved = $candidate['unresolved'];

            if (! is_array($bindings)
                || ! is_array($candidateWarnings) || ! array_is_list($candidateWarnings)
                || ! is_array($unresolved) || ! array_is_list($unresolved)) {
                throw new BlueprintMalformedResponseException('The interpretation provider returned an invalid candidate.');
            }

            $normalized[] = [
                'bindings' => $bindings,
                'warnings' => $candidateWarnings,
                'unresolved' => $unresolved,
            ];
        }

        return new BlueprintImportProviderInterpretation(
            $kind,
            $normalized,
            $warnings,
            $decoded['metadata'],
        );
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
                'maxOutputTokens' => (int) config('question_blueprint.import_interpretation_max_output_tokens', 16_384),
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
