<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Data\Generations\BlueprintGenerationContext;
use App\Data\Generations\EssayQuestionCandidate;
use App\Data\Generations\GenerationProviderRequest;
use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\TrueFalseQuestionCandidate;
use App\Enums\AssessmentType;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationErrorCode;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
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

        config(['generation.prompt_version' => 'mcq-v3']);
        $this->assertSame('mcq-v3', $builder->version());
    }

    public function test_v2_http_payload_includes_blueprint_row_and_forbids_heading_recall(): void
    {
        config(['generation.prompt_version' => McqPromptBuilder::V2]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::questions(1)),
                200,
            ),
        ]);

        $this->provider()->generate(new GenerationProviderRequest(
            outputLanguage: OutputLanguage::ID,
            difficultyLevel: DifficultyLevel::MEDIUM,
            assessmentType: AssessmentType::FORMATIVE,
            requestedCount: 1,
            acceptedQuestionTexts: [],
            materialContent: 'Fotosintesis membutuhkan cahaya.',
            purpose: GenerationAttemptPurpose::INITIAL,
            model: (string) config('generation.primary_model'),
            blueprintContext: new BlueprintGenerationContext(
                objective: 'Tujuan provider.',
                topic: 'Topik provider',
                indicator: 'Indikator provider.',
                cognitiveLevel: CognitiveLevel::Evaluate,
                difficulty: DifficultyLevel::HARD,
                assessmentType: AssessmentType::SUMMATIVE,
                requestedCount: 1,
            ),
        ));

        Http::assertSent(function ($request): bool {
            $system = $request->data()['systemInstruction']['parts'][0]['text'] ?? '';
            $user = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('which heading appears', $system);
            $this->assertStringContainsString('plausible statements from the same subject domain', $system);
            $this->assertStringContainsString('<<<BLUEPRINT_ROW>>>', $user);
            $this->assertStringContainsString('Tujuan provider.', $user);
            $this->assertStringContainsString('Cognitive level: evaluate', $user);
            $this->assertStringContainsString('<<<MATERIAL>>>', $user);

            return true;
        });
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

    public function test_true_false_schema_uses_json_boolean_and_parses_only_true_false_candidates(): void
    {
        config(['generation.true_false_prompt_version' => 'true-false-v1']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::trueFalseQuestions(2, 'TfProvider')),
                200,
            ),
        ]);

        $result = $this->provider()->generate($this->typedRequest(QuestionType::TRUE_FALSE, 2));

        $this->assertCount(2, $result->candidates);
        $this->assertContainsOnlyInstancesOf(TrueFalseQuestionCandidate::class, $result->candidates);
        $this->assertFalse(collect($result->candidates)->contains(
            fn (mixed $candidate): bool => $candidate instanceof McqQuestionCandidate || $candidate instanceof EssayQuestionCandidate,
        ));

        Http::assertSent(function ($request): bool {
            $schema = $request->data()['generationConfig']['responseSchema'] ?? [];
            $item = $schema['properties']['questions']['items']['properties'] ?? [];
            $this->assertSame('boolean', $item['correct_answer']['type'] ?? null);
            $this->assertArrayNotHasKey('options', $item);
            $this->assertSame(
                ['question', 'correct_answer', 'explanation'],
                $schema['properties']['questions']['items']['required'] ?? null,
            );

            return true;
        });
    }

    public function test_essay_schema_requires_four_fields_and_parses_only_essay_candidates(): void
    {
        config(['generation.essay_prompt_version' => 'essay-v1']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::essayQuestions(2, 'EsProvider')),
                200,
            ),
        ]);

        $result = $this->provider()->generate($this->typedRequest(QuestionType::ESSAY, 2));

        $this->assertCount(2, $result->candidates);
        $this->assertContainsOnlyInstancesOf(EssayQuestionCandidate::class, $result->candidates);
        $this->assertFalse(collect($result->candidates)->contains(
            fn (mixed $candidate): bool => $candidate instanceof McqQuestionCandidate || $candidate instanceof TrueFalseQuestionCandidate,
        ));

        Http::assertSent(function ($request): bool {
            $schema = $request->data()['generationConfig']['responseSchema'] ?? [];
            $item = $schema['properties']['questions']['items']['properties'] ?? [];
            $this->assertArrayHasKey('model_answer', $item);
            $this->assertArrayHasKey('rubric', $item);
            $this->assertArrayNotHasKey('options', $item);
            $this->assertSame(
                ['question', 'model_answer', 'rubric', 'explanation'],
                $schema['properties']['questions']['items']['required'] ?? null,
            );

            return true;
        });
    }

    public function test_cross_type_prompt_identity_fails_before_http(): void
    {
        config(['generation.true_false_prompt_version' => 'true-false-v1']);
        Http::fake();

        try {
            $this->provider()->generate($this->typedRequest(
                QuestionType::TRUE_FALSE,
                1,
                promptVersion: McqPromptBuilder::V3,
            ));
            $this->fail('Cross-type prompt identity must fail before HTTP.');
        } catch (GenerationConfigurationException) {
            Http::assertNothingSent();
        }
    }

    public function test_unsupported_true_false_prompt_identity_fails_before_http(): void
    {
        config(['generation.true_false_prompt_version' => 'true-false-v1']);
        Http::fake();

        try {
            $this->provider()->generate($this->typedRequest(
                QuestionType::TRUE_FALSE,
                1,
                promptVersion: 'true-false-v9',
            ));
            $this->fail('Unsupported True/False identity must fail before HTTP.');
        } catch (GenerationConfigurationException) {
            Http::assertNothingSent();
        }
    }

    public function test_blank_true_false_and_essay_prompt_configuration_fails_before_http(): void
    {
        Http::fake();

        config(['generation.true_false_prompt_version' => '']);

        try {
            $this->provider()->generate($this->typedRequest(QuestionType::TRUE_FALSE, 1));
            $this->fail('Blank True/False prompt configuration must fail before HTTP.');
        } catch (GenerationConfigurationException) {
            Http::assertNothingSent();
        }

        config([
            'generation.true_false_prompt_version' => 'true-false-v1',
            'generation.essay_prompt_version' => '',
        ]);

        try {
            $this->provider()->generate($this->typedRequest(QuestionType::ESSAY, 1));
            $this->fail('Blank Essay prompt configuration must fail before HTTP.');
        } catch (GenerationConfigurationException) {
            Http::assertNothingSent();
        }
    }

    public function test_typed_prompts_carry_immutable_blueprint_row_attributes(): void
    {
        config([
            'generation.true_false_prompt_version' => 'true-false-v1',
            'generation.essay_prompt_version' => 'essay-v1',
        ]);
        $blueprint = new BlueprintGenerationContext(
            objective: 'Tujuan typed.',
            topic: 'Topik typed',
            indicator: 'Indikator typed.',
            cognitiveLevel: CognitiveLevel::Evaluate,
            difficulty: DifficultyLevel::HARD,
            assessmentType: AssessmentType::SUMMATIVE,
            requestedCount: 2,
        );

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::trueFalseQuestions(2, 'TfRow')),
                200,
            ),
        ]);
        $this->provider()->generate($this->typedRequest(
            QuestionType::TRUE_FALSE,
            2,
            blueprint: $blueprint,
        ));
        Http::assertSent(function ($request): bool {
            $user = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('<<<BLUEPRINT_ROW>>>', $user);
            $this->assertStringContainsString('Tujuan typed.', $user);
            $this->assertStringContainsString('Topik typed', $user);
            $this->assertStringContainsString('Indikator typed.', $user);
            $this->assertStringContainsString('Cognitive level: evaluate', $user);
            $this->assertStringContainsString('Question type: true_false', $user);

            return true;
        });

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::essayQuestions(2, 'EsRow')),
                200,
            ),
        ]);
        $this->provider()->generate($this->typedRequest(
            QuestionType::ESSAY,
            2,
            blueprint: $blueprint,
        ));
        Http::assertSent(function ($request): bool {
            $user = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('<<<BLUEPRINT_ROW>>>', $user);
            $this->assertStringContainsString('Tujuan typed.', $user);
            $this->assertStringContainsString('Question type: essay', $user);
            $this->assertStringNotContainsString('"options"', json_encode($request->data()['generationConfig']['responseSchema'] ?? []));

            return true;
        });
    }

    public function test_true_false_repair_includes_exact_remaining_counts(): void
    {
        config(['generation.true_false_prompt_version' => 'true-false-v1']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success([
                    GeminiFakeResponses::trueFalse('Repair false 1', false),
                    GeminiFakeResponses::trueFalse('Repair false 2', false),
                ]),
                200,
            ),
        ]);

        $this->provider()->repair($this->typedRequest(
            QuestionType::TRUE_FALSE,
            2,
            purpose: GenerationAttemptPurpose::REPAIR,
            accepted: ['Accepted true 1', 'Accepted true 2'],
            remainingTrue: 0,
            remainingFalse: 2,
        ));

        Http::assertSent(function ($request): bool {
            $user = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('exactly 0 true and 2 false', $user);
            $this->assertStringContainsString('Accepted true 1', $user);
            $this->assertSame('application/json', $request->data()['generationConfig']['responseMimeType'] ?? null);

            return true;
        });
    }

    public function test_essay_repair_requests_only_missing_slots(): void
    {
        config(['generation.essay_prompt_version' => 'essay-v1']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::essayQuestions(1, 'RepairEssay')),
                200,
            ),
        ]);

        $this->provider()->repair($this->typedRequest(
            QuestionType::ESSAY,
            1,
            purpose: GenerationAttemptPurpose::REPAIR,
            accepted: ['Already accepted essay'],
        ));

        Http::assertSent(function ($request): bool {
            $user = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('Requested count: 1', $user);
            $this->assertStringContainsString('missing or invalid slots', $user);
            $this->assertStringContainsString('Already accepted essay', $user);

            return true;
        });
    }

    public function test_typed_provider_result_does_not_include_raw_prompt_or_body(): void
    {
        config(['generation.true_false_prompt_version' => 'true-false-v1']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::trueFalseQuestions(1, 'Meta')),
                200,
            ),
        ]);

        $result = $this->provider()->generate($this->typedRequest(QuestionType::TRUE_FALSE, 1));
        $encoded = json_encode($result->metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('systemInstruction', $encoded);
        $this->assertStringNotContainsString('<<<MATERIAL>>>', $encoded);
        $this->assertStringNotContainsString('You are a true/false', $encoded);
        $this->assertSame('google_gemini', $result->metadata->provider);
        $this->assertSame('STOP', $result->metadata->finishReason);
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

    /**
     * @param  list<string>  $accepted
     */
    private function typedRequest(
        QuestionType $type,
        int $count,
        GenerationAttemptPurpose $purpose = GenerationAttemptPurpose::INITIAL,
        array $accepted = [],
        ?string $promptVersion = null,
        ?int $remainingTrue = null,
        ?int $remainingFalse = null,
        ?BlueprintGenerationContext $blueprint = null,
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
            blueprintContext: $blueprint,
            questionType: $type,
            promptVersion: $promptVersion,
            trueFalseRemainingTrue: $remainingTrue,
            trueFalseRemainingFalse: $remainingFalse,
        );
    }
}
