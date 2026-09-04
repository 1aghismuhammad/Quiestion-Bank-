<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Data\MaterialProfiles\ProfileElementSummary;
use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Data\MaterialProfiles\ProfileReduceRequest;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileStepPurpose;
use App\Exceptions\MaterialProfiles\MaterialProfileMalformedResponseException;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderPermanentException;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderTransientException;
use App\Services\AI\GeminiMaterialProfileProvider;
use App\Services\AI\MaterialProfilePromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\MaterialProfiles\GeminiProfileFakeResponses;
use Tests\TestCase;

class GeminiMaterialProfileProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'material_profile.api_key' => 'test-profile-key',
            'material_profile.api_base' => 'https://generativelanguage.googleapis.com/v1beta',
            'material_profile.primary_model' => 'gemini-3.5-flash-lite',
            'material_profile.map_prompt_version' => 'profile-map-v1',
            'material_profile.reduce_prompt_version' => 'profile-reduce-v1',
        ]);
    }

    public function test_map_call_requests_json_and_returns_typed_candidates(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiProfileFakeResponses::success(GeminiProfileFakeResponses::mapPayload([
                    GeminiProfileFakeResponses::observation('topic', 'Fotosintesis', 'Fotosintesis', 0, 12),
                    GeminiProfileFakeResponses::observation('objective', 'Menjelaskan proses', 'adalah proses', 13, 26),
                ])),
                200,
            ),
        ]);

        $result = $this->provider()->analyzeChunk($this->mapRequest());

        $this->assertCount(2, $result->candidates);
        $this->assertSame('topic', $result->candidates[0]->kind);
        $this->assertSame('Fotosintesis', $result->candidates[0]->text);
        $this->assertSame(0, $result->candidates[0]->evidenceStart);
        $this->assertSame(12, $result->candidates[0]->evidenceEnd);

        $this->assertSame(GeminiMaterialProfileProvider::PROVIDER_NAME, $result->metadata->provider);
        $this->assertSame('gemini-3.5-flash-lite', $result->metadata->model);
        $this->assertSame('profile-map-v1', $result->metadata->promptVersion);
        $this->assertSame(MaterialProfileStepPurpose::MAP, $result->metadata->purpose);
        $this->assertSame(120, $result->metadata->inputTokens);
        $this->assertSame(45, $result->metadata->outputTokens);
        $this->assertSame(165, $result->metadata->totalTokens);
        $this->assertIsInt($result->metadata->latencyMs);

        Http::assertSent(function ($request): bool {
            $this->assertSame('test-profile-key', $request->header('x-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString('test-profile-key', $request->url());
            $this->assertStringContainsString('models/gemini-3.5-flash-lite:generateContent', $request->url());

            $body = $request->data();
            $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
            $this->assertArrayHasKey('responseSchema', $body['generationConfig']);
            $this->assertArrayHasKey('maxOutputTokens', $body['generationConfig']);

            $userText = $body['contents'][0]['parts'][0]['text'];
            $this->assertStringContainsString('<<<CORE>>>', $userText);
            $this->assertStringContainsString('Fotosintesis adalah proses', $userText);

            return true;
        });
    }

    public function test_reduce_call_returns_typed_suggested_candidates(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiProfileFakeResponses::success(GeminiProfileFakeResponses::reducePayload([
                    GeminiProfileFakeResponses::element('topic', 'Fotosintesis'),
                    GeminiProfileFakeResponses::element('objective', 'Menjelaskan tahapan fotosintesis'),
                    GeminiProfileFakeResponses::element('indicator', 'Menyebutkan tiga faktor'),
                ])),
                200,
            ),
        ]);

        $result = $this->provider()->reduceProfile($this->reduceRequest());

        $this->assertCount(3, $result->candidates);
        $this->assertSame('topic', $result->candidates[0]->kind);
        $this->assertSame('Fotosintesis', $result->candidates[0]->text);
        $this->assertSame(MaterialProfileStepPurpose::REDUCE, $result->metadata->purpose);
        $this->assertSame('profile-reduce-v1', $result->metadata->promptVersion);

        Http::assertSent(function ($request): bool {
            $userText = $request->data()['contents'][0]['parts'][0]['text'];
            $this->assertStringContainsString('Fotosintesis', $userText);
            $this->assertStringNotContainsString('Fotosintesis adalah proses tumbuhan', $userText);

            return true;
        });
    }

    public function test_missing_api_key_fails_permanently_without_http(): void
    {
        config(['material_profile.api_key' => '']);
        Http::fake();

        try {
            $this->provider()->analyzeChunk($this->mapRequest());
            $this->fail('Expected a permanent provider exception.');
        } catch (MaterialProfileProviderPermanentException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertSame(MaterialProfileAttemptErrorCode::ProviderHttp, $exception->attemptErrorCode);
        }

        Http::assertNothingSent();
    }

    public function test_authentication_rejection_is_permanent(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'denied'], 403),
        ]);

        try {
            $this->provider()->analyzeChunk($this->mapRequest());
            $this->fail('Expected a permanent provider exception.');
        } catch (MaterialProfileProviderPermanentException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertStringNotContainsString('denied', $exception->getMessage());
        }
    }

    public function test_rate_limiting_is_transient_and_carries_a_bounded_retry_hint(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'rate'], 429, ['Retry-After' => '9'])
                ->push(['error' => 'rate'], 429, ['Retry-After' => '9999']),
        ]);

        try {
            $this->provider()->analyzeChunk($this->mapRequest());
            $this->fail('Expected a transient provider exception.');
        } catch (MaterialProfileProviderTransientException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertSame(9, $exception->retryAfterSeconds);
            $this->assertSame(MaterialProfileAttemptErrorCode::ProviderHttp, $exception->attemptErrorCode);
        }

        try {
            $this->provider()->analyzeChunk($this->mapRequest());
            $this->fail('Expected a transient provider exception.');
        } catch (MaterialProfileProviderTransientException $exception) {
            $this->assertSame(30, $exception->retryAfterSeconds);
        }
    }

    public function test_server_errors_are_transient(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'down'], 503),
        ]);

        $exception = null;

        try {
            $this->provider()->reduceProfile($this->reduceRequest());
        } catch (MaterialProfileProviderTransientException $thrown) {
            $exception = $thrown;
        }

        $this->assertNotNull($exception);
        $this->assertTrue($exception->isRetryable());
        $this->assertSame(MaterialProfileAttemptErrorCode::ProviderHttp, $exception->attemptErrorCode);
    }

    public function test_client_rejection_other_than_rate_limit_is_permanent(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $this->expectException(MaterialProfileProviderPermanentException::class);
        $this->provider()->analyzeChunk($this->mapRequest());
    }

    public function test_connection_failure_maps_to_a_timeout_attempt_code(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            $this->provider()->analyzeChunk($this->mapRequest());
            $this->fail('Expected a transient provider exception.');
        } catch (MaterialProfileProviderTransientException $exception) {
            $this->assertSame(MaterialProfileAttemptErrorCode::ProviderTimeout, $exception->attemptErrorCode);
            $this->assertStringNotContainsString('cURL', $exception->getMessage());
        }
    }

    public function test_non_json_candidate_text_is_malformed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiProfileFakeResponses::rawText('bukan json'),
                200,
            ),
        ]);

        try {
            $this->provider()->analyzeChunk($this->mapRequest());
            $this->fail('Expected a malformed response exception.');
        } catch (MaterialProfileMalformedResponseException $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertSame(MaterialProfileAttemptErrorCode::SchemaInvalid, $exception->attemptErrorCode);
        }
    }

    public function test_missing_observations_key_is_malformed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiProfileFakeResponses::success(['something_else' => []]),
                200,
            ),
        ]);

        $this->expectException(MaterialProfileMalformedResponseException::class);
        $this->provider()->analyzeChunk($this->mapRequest());
    }

    public function test_missing_elements_key_is_malformed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiProfileFakeResponses::success(['observations' => []]),
                200,
            ),
        ]);

        $this->expectException(MaterialProfileMalformedResponseException::class);
        $this->provider()->reduceProfile($this->reduceRequest());
    }

    public function test_empty_candidate_list_is_malformed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => []], 200),
        ]);

        $this->expectException(MaterialProfileMalformedResponseException::class);
        $this->provider()->analyzeChunk($this->mapRequest());
    }

    public function test_map_prompt_separates_overlap_from_canonical_core(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiProfileFakeResponses::success(GeminiProfileFakeResponses::mapPayload([])),
                200,
            ),
        ]);

        $this->provider()->analyzeChunk(new ProfileMapRequest(
            profileVersionId: 7,
            chunkIndex: 2,
            coreText: 'Inti kanonik bagian dua.',
            overlapText: 'Ekor bagian satu.',
            coreCharStart: 400,
            coreCharEnd: 424,
            model: 'gemini-3.5-flash-lite',
            promptVersion: 'profile-map-v1',
        ));

        Http::assertSent(function ($request): bool {
            $userText = $request->data()['contents'][0]['parts'][0]['text'];
            $overlapAt = strpos($userText, 'Ekor bagian satu.');
            $coreAt = strpos($userText, 'Inti kanonik bagian dua.');

            $this->assertIsInt($overlapAt);
            $this->assertIsInt($coreAt);
            $this->assertLessThan($coreAt, $overlapAt, 'Overlap is labelled and presented before the core.');
            $this->assertStringContainsString('<<<OVERLAP>>>', $userText);
            $this->assertStringContainsString('<<<END_OVERLAP>>>', $userText);
            $this->assertStringContainsString('<<<CORE>>>', $userText);
            $this->assertStringContainsString('<<<END_CORE>>>', $userText);
            $this->assertStringContainsString('Segment index: 2', $userText);
            $this->assertStringContainsString('400..424', $userText);

            return true;
        });
    }

    public function test_prompt_versions_track_configuration(): void
    {
        $builder = $this->app->make(MaterialProfilePromptBuilder::class);

        $this->assertSame('profile-map-v1', $builder->mapVersion());
        $this->assertSame('profile-reduce-v1', $builder->reduceVersion());

        config([
            'material_profile.map_prompt_version' => 'profile-map-v2',
            'material_profile.reduce_prompt_version' => 'profile-reduce-v2',
        ]);

        $this->assertSame('profile-map-v2', $builder->mapVersion());
        $this->assertSame('profile-reduce-v2', $builder->reduceVersion());
    }

    public function test_b1_timeout_configuration_is_preserved(): void
    {
        $this->assertSame(60, (int) config('material_profile.provider_http_timeout_seconds'));
        $this->assertSame(10, (int) config('material_profile.provider_connect_timeout_seconds'));
        $this->assertSame(270, (int) config('material_profile.job_timeout_seconds'));
        $this->assertSame(120, (int) config('material_profile.processing_lease_seconds'));
        $this->assertSame(900, (int) config('material_profile.queued_abandonment_seconds'));
    }

    public function test_provider_identity_is_google_gemini(): void
    {
        $this->assertSame(
            GeminiMaterialProfileProvider::PROVIDER_NAME,
            $this->provider()->identity()->name,
        );
    }

    public function test_response_schemas_declare_max_items(): void
    {
        $builder = $this->app->make(MaterialProfilePromptBuilder::class);

        $this->assertSame(10, $builder->mapResponseSchema()['properties']['observations']['maxItems']);
        $this->assertSame(40, $builder->reduceResponseSchema()['properties']['elements']['maxItems']);
    }

    private function provider(): GeminiMaterialProfileProvider
    {
        return $this->app->make(GeminiMaterialProfileProvider::class);
    }

    private function mapRequest(): ProfileMapRequest
    {
        return new ProfileMapRequest(
            profileVersionId: 1,
            chunkIndex: 0,
            coreText: 'Fotosintesis adalah proses tumbuhan mengubah cahaya menjadi energi.',
            overlapText: null,
            coreCharStart: 0,
            coreCharEnd: 67,
            model: 'gemini-3.5-flash-lite',
            promptVersion: 'profile-map-v1',
        );
    }

    private function reduceRequest(): ProfileReduceRequest
    {
        return new ProfileReduceRequest(
            profileVersionId: 1,
            summaries: [
                new ProfileElementSummary(
                    MaterialProfileElementKind::TOPIC,
                    'Fotosintesis',
                    'core-0:0-12',
                    0,
                    12,
                ),
            ],
            model: 'gemini-3.5-flash-lite',
            promptVersion: 'profile-reduce-v1',
        );
    }
}
