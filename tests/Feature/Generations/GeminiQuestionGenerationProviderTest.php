<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Data\Generations\GenerationProviderRequest;
use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationErrorCode;
use App\Enums\OutputLanguage;
use App\Exceptions\Generations\GenerationConfigurationException;
use App\Exceptions\Generations\GenerationMalformedResponseException;
use App\Exceptions\Generations\GenerationProviderAuthException;
use App\Exceptions\Generations\GenerationProviderTransientException;
use App\Services\AI\GeminiModelSelector;
use App\Services\AI\GeminiQuestionGenerationProvider;
use App\Services\AI\McqPromptBuilder;
use Illuminate\Support\Facades\Http;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\TestCase;

class GeminiQuestionGenerationProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'generation.api_key' => 'test-key',
            'generation.primary_model' => 'gemini-3.5-flash-lite',
            'generation.fallback_model' => 'gemini-3.7-flash',
            'generation.prompt_version' => 'mcq-v1',
        ]);
    }

    public function test_generate_parses_structured_questions_and_token_metadata(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::questions(2)),
                200,
            ),
        ]);

        $result = $this->provider()->generate($this->request(2));

        $this->assertCount(2, $result->candidates);
        $this->assertSame('google_gemini', $result->metadata->provider);
        $this->assertSame('gemini-3.5-flash-lite', $result->metadata->model);
        $this->assertSame(10, $result->metadata->inputTokens);
        $this->assertSame(20, $result->metadata->outputTokens);
        $this->assertSame('STOP', $result->metadata->finishReason);

        Http::assertSent(function ($request): bool {
            $this->assertSame('test-key', $request->header('x-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString('test-key', $request->url());
            $body = $request->data();
            $this->assertSame('application/json', $body['generationConfig']['responseMimeType'] ?? null);
            $this->assertArrayHasKey('responseSchema', $body['generationConfig']);
            $this->assertArrayHasKey('maxOutputTokens', $body['generationConfig']);
            $this->assertArrayNotHasKey('temperature', $body['generationConfig']);
            $this->assertArrayNotHasKey('topP', $body['generationConfig']);
            $this->assertArrayNotHasKey('topK', $body['generationConfig']);
            $this->assertArrayNotHasKey('top_p', $body['generationConfig']);
            $this->assertArrayNotHasKey('top_k', $body['generationConfig']);

            return true;
        });
    }

    public function test_missing_api_key_fails_without_http(): void
    {
        config(['generation.api_key' => '']);
        Http::fake();

        $this->expectException(GenerationConfigurationException::class);
        $this->provider()->generate($this->request(1));
    }

    public function test_auth_failure_is_permanent(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'denied'], 403),
        ]);

        $this->expectException(GenerationProviderAuthException::class);
        $this->provider()->generate($this->request(1));
    }

    public function test_timeout_and_429_and_5xx_are_transient(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'rate'], 429, ['Retry-After' => '9']),
        ]);

        try {
            $this->provider()->generate($this->request(1));
            $this->fail('Expected transient exception');
        } catch (GenerationProviderTransientException $exception) {
            $this->assertSame(9, $exception->retryAfterSeconds());
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'down'], 503),
        ]);

        $this->expectException(GenerationProviderTransientException::class);
        $this->provider()->generate($this->request(1));
    }

    public function test_malformed_json_throws(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'not-json']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(GenerationMalformedResponseException::class);
        $this->provider()->generate($this->request(1));
    }

    public function test_unexpected_runtime_exception_is_not_classified_as_transient(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('unexpected provider client bug');
        });

        try {
            $this->provider()->generate($this->request(1));
            $this->fail('Expected RuntimeException');
        } catch (GenerationProviderTransientException) {
            $this->fail('Unexpected runtime exceptions must not be classified as transient');
        } catch (\RuntimeException $exception) {
            $this->assertSame('unexpected provider client bug', $exception->getMessage());
        }
    }

    public function test_model_selector_uses_fallback_on_attempt_three_when_eligible(): void
    {
        $selector = $this->app->make(GeminiModelSelector::class);

        $this->assertSame('gemini-3.5-flash-lite', $selector->modelForAttempt(1));
        $this->assertSame('gemini-3.5-flash-lite', $selector->modelForAttempt(2));
        $this->assertSame(
            'gemini-3.7-flash',
            $selector->modelForAttempt(3, GenerationErrorCode::ProviderTimeout),
        );
        $this->assertSame(
            'gemini-3.5-flash-lite',
            $selector->modelForAttempt(3, GenerationErrorCode::Auth),
        );
    }

    public function test_prompt_builder_version_reads_current_config(): void
    {
        $builder = $this->app->make(McqPromptBuilder::class);
        $this->assertSame('mcq-v1', $builder->version());

        config(['generation.prompt_version' => 'mcq-v2']);
        $this->assertSame('mcq-v2', $builder->version());
    }

    public function test_repair_prompt_asks_only_for_the_requested_count(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Repair')),
                200,
            ),
        ]);

        $this->provider()->repair($this->request(
            1,
            GenerationAttemptPurpose::REPAIR,
            ['Already accepted question'],
        ));

        Http::assertSent(function ($request): bool {
            $userText = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('Requested count: 1', $userText);
            $this->assertStringContainsString('Already accepted question', $userText);
            $this->assertStringContainsString('replacement', strtolower($userText));

            return true;
        });
    }

    private function provider(): GeminiQuestionGenerationProvider
    {
        return $this->app->make(GeminiQuestionGenerationProvider::class);
    }

    /**
     * @param  list<string>  $accepted
     */
    private function request(
        int $count,
        GenerationAttemptPurpose $purpose = GenerationAttemptPurpose::INITIAL,
        array $accepted = [],
    ): GenerationProviderRequest {
        return new GenerationProviderRequest(
            outputLanguage: OutputLanguage::ID,
            difficultyLevel: DifficultyLevel::MEDIUM,
            assessmentType: AssessmentType::FORMATIVE,
            requestedCount: $count,
            acceptedQuestionTexts: $accepted,
            materialContent: 'Fotosintesis membutuhkan cahaya.',
            purpose: $purpose,
            model: (string) config('generation.primary_model'),
        );
    }
}
