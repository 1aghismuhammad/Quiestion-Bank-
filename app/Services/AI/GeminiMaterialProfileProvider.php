<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AI\MaterialProfileAnalysisProvider;
use App\Data\MaterialProfiles\ExtractedProfileCandidate;
use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Data\MaterialProfiles\ProfileMapResult;
use App\Data\MaterialProfiles\ProfileProviderAttemptMetadata;
use App\Data\MaterialProfiles\ProfileProviderIdentity;
use App\Data\MaterialProfiles\ProfileReduceRequest;
use App\Data\MaterialProfiles\ProfileReduceResult;
use App\Data\MaterialProfiles\SuggestedProfileCandidate;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileStepPurpose;
use App\Exceptions\MaterialProfiles\MaterialProfileMalformedResponseException;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderPermanentException;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderTransientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class GeminiMaterialProfileProvider implements MaterialProfileAnalysisProvider
{
    public const PROVIDER_NAME = 'google_gemini';

    public function __construct(private MaterialProfilePromptBuilder $promptBuilder) {}

    public function identity(): ProfileProviderIdentity
    {
        return new ProfileProviderIdentity(self::PROVIDER_NAME);
    }

    public function analyzeChunk(ProfileMapRequest $request): ProfileMapResult
    {
        $decoded = $this->call(
            $request->model,
            $this->promptBuilder->mapSystemInstruction(),
            $this->promptBuilder->mapUserPrompt($request),
            $this->promptBuilder->mapResponseSchema(),
            MaterialProfileStepPurpose::MAP,
            $request->promptVersion,
        );

        $observations = $decoded['payload']['observations'] ?? null;

        if (! is_array($observations)) {
            throw new MaterialProfileMalformedResponseException(
                'The material profile provider JSON is missing observations.',
            );
        }

        $candidates = [];

        foreach ($observations as $observation) {
            if (! is_array($observation)) {
                throw new MaterialProfileMalformedResponseException(
                    'The material profile provider returned a non-object observation.',
                );
            }

            $candidates[] = new ExtractedProfileCandidate(
                $observation['kind'] ?? null,
                $observation['text'] ?? null,
                $observation['evidence_excerpt'] ?? null,
                $observation['evidence_start'] ?? null,
                $observation['evidence_end'] ?? null,
            );
        }

        return new ProfileMapResult($candidates, $decoded['metadata']);
    }

    public function reduceProfile(ProfileReduceRequest $request): ProfileReduceResult
    {
        $decoded = $this->call(
            $request->model,
            $this->promptBuilder->reduceSystemInstruction(),
            $this->promptBuilder->reduceUserPrompt($request),
            $this->promptBuilder->reduceResponseSchema(),
            MaterialProfileStepPurpose::REDUCE,
            $request->promptVersion,
        );

        $elements = $decoded['payload']['elements'] ?? null;

        if (! is_array($elements)) {
            throw new MaterialProfileMalformedResponseException(
                'The material profile provider JSON is missing elements.',
            );
        }

        $candidates = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                throw new MaterialProfileMalformedResponseException(
                    'The material profile provider returned a non-object element.',
                );
            }

            $candidates[] = new SuggestedProfileCandidate(
                $element['kind'] ?? null,
                $element['text'] ?? null,
            );
        }

        return new ProfileReduceResult($candidates, $decoded['metadata']);
    }

    /**
     * @param  array<string, mixed>  $responseSchema
     * @return array{payload: array<string, mixed>, metadata: ProfileProviderAttemptMetadata}
     */
    private function call(
        string $model,
        string $systemInstruction,
        string $userPrompt,
        array $responseSchema,
        MaterialProfileStepPurpose $purpose,
        string $promptVersion,
    ): array {
        $apiKey = config('material_profile.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new MaterialProfileProviderPermanentException(
                MaterialProfileAttemptErrorCode::ProviderHttp,
                'The material profile provider API key is not configured.',
            );
        }

        $url = rtrim((string) config('material_profile.api_base'), '/').'/models/'.$model.':generateContent';

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
                'maxOutputTokens' => (int) config('material_profile.max_output_tokens', 8192),
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ];

        $started = hrtime(true);

        try {
            $response = Http::timeout((int) config('material_profile.provider_http_timeout_seconds', 60))
                ->connectTimeout((int) config('material_profile.provider_connect_timeout_seconds', 10))
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($url, $payload);
        } catch (ConnectionException) {
            // Both read timeouts and connection failures surface here. Neither
            // produced an HTTP status, so both are network-level and retryable.
            throw new MaterialProfileProviderTransientException(
                MaterialProfileAttemptErrorCode::ProviderTimeout,
                'The material profile provider did not respond in time.',
            );
        }

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->throwForHttpStatus($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw new MaterialProfileMalformedResponseException(
                'The material profile provider returned a non-JSON body.',
            );
        }

        try {
            $decoded = json_decode($this->candidateText($body), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MaterialProfileMalformedResponseException(
                'The material profile provider returned invalid JSON.',
            );
        }

        if (! is_array($decoded)) {
            throw new MaterialProfileMalformedResponseException(
                'The material profile provider returned invalid JSON.',
            );
        }

        $usage = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];

        return [
            'payload' => $decoded,
            'metadata' => new ProfileProviderAttemptMetadata(
                provider: self::PROVIDER_NAME,
                model: $model,
                promptVersion: $promptVersion,
                purpose: $purpose,
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
            throw new MaterialProfileProviderPermanentException(
                MaterialProfileAttemptErrorCode::ProviderHttp,
                'The material profile provider rejected the credentials.',
            );
        }

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new MaterialProfileProviderTransientException(
                MaterialProfileAttemptErrorCode::ProviderHttp,
                'The material profile provider rate-limited the request.',
                is_numeric($retryAfter) ? min(30, max(0, (int) $retryAfter)) : null,
            );
        }

        if ($status >= 500) {
            throw new MaterialProfileProviderTransientException(
                MaterialProfileAttemptErrorCode::ProviderHttp,
                'The material profile provider is unavailable.',
            );
        }

        if ($response->failed()) {
            throw new MaterialProfileProviderPermanentException(
                MaterialProfileAttemptErrorCode::ProviderHttp,
                'The material profile provider rejected the request.',
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
            throw new MaterialProfileMalformedResponseException(
                'The material profile provider returned no candidates.',
            );
        }

        $parts = $candidates[0]['content']['parts'] ?? null;

        if (! is_array($parts)) {
            throw new MaterialProfileMalformedResponseException(
                'The material profile provider returned an empty candidate.',
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
            throw new MaterialProfileMalformedResponseException(
                'The material profile provider returned an empty candidate.',
            );
        }

        return $text;
    }
}
