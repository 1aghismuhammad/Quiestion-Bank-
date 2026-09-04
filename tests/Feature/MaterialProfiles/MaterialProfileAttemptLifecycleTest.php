<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MaterialProfiles\GeminiProfileFakeResponses;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

/**
 * Drives the real Gemini boundary through faked HTTP so provider failures are
 * proven end to end: HTTP outcome, Attempt audit row, retry policy, and
 * terminal failure.
 */
class MaterialProfileAttemptLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use RunsMaterialProfileWorkflows;

    private const CONTENT = 'Fotosintesis adalah proses tumbuhan mengubah cahaya matahari menjadi energi kimia.';

    private User $user;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config([
            'material_profile.api_key' => 'test-profile-key',
            'material_profile.primary_model' => 'gemini-3.5-flash-lite',
        ]);

        $this->user = User::factory()->create();
        $this->material = Material::factory()->text()->for($this->user)->create(['content' => self::CONTENT]);
    }

    public function test_each_provider_call_creates_exactly_one_started_attempt(): void
    {
        $this->fakeSuccessfulProvider();

        $version = $this->completeProfileAnalysis($this->user, $this->material);

        Http::assertSentCount(2);
        $this->assertSame(MaterialProfileStatus::READY, $version->status);

        $attempts = MaterialProfileAttempt::query()->orderBy('profile_attempt_id')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame(
            [MaterialProfileStepPurpose::MAP, MaterialProfileStepPurpose::REDUCE],
            $attempts->pluck('purpose')->all(),
        );

        foreach ($attempts as $attempt) {
            $this->assertSame(MaterialProfileAttemptStatus::SUCCEEDED, $attempt->status);
            $this->assertSame(1, (int) $attempt->attempt_number);
            $this->assertSame('google_gemini', $attempt->provider);
            $this->assertSame('gemini-3.5-flash-lite', $attempt->model);
            $this->assertNotNull($attempt->started_at);
            $this->assertNotNull($attempt->finished_at);
            $this->assertSame(120, (int) $attempt->input_tokens);
            $this->assertSame(45, (int) $attempt->output_tokens);
            $this->assertSame(165, (int) $attempt->total_tokens);
            $this->assertNotNull($attempt->latency_ms);
            $this->assertNull($attempt->error_code);
        }

        $this->assertSame(
            ['profile-map-v1', 'profile-reduce-v1'],
            $attempts->pluck('prompt_version')->all(),
        );
    }

    public function test_successful_attempt_metadata_never_carries_prompts_or_provider_bodies(): void
    {
        $this->fakeSuccessfulProvider();
        $this->completeProfileAnalysis($this->user, $this->material);

        foreach (MaterialProfileAttempt::query()->get() as $attempt) {
            $serialized = json_encode($attempt->getAttributes(), JSON_UNESCAPED_UNICODE);
            $this->assertIsString($serialized);

            foreach (['<<<CORE>>>', '<<<SUMMARIES>>>', 'usageMetadata', 'candidates', 'systemInstruction', self::CONTENT, 'test-profile-key'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $serialized);
            }
        }
    }

    public function test_attempts_table_exposes_no_raw_payload_columns(): void
    {
        $columns = Schema::getColumnListing('material_profile_attempts');

        $this->assertSame([
            'profile_attempt_id',
            'profile_version_id',
            'profile_step_id',
            'attempt_number',
            'provider',
            'model',
            'prompt_version',
            'purpose',
            'status',
            'input_tokens',
            'output_tokens',
            'total_tokens',
            'latency_ms',
            'error_code',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ], $columns);
    }

    public function test_connection_failure_is_recorded_as_a_provider_timeout(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds');
        });

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $attempt = MaterialProfileAttempt::query()->firstOrFail();
        $this->assertSame(MaterialProfileAttemptStatus::FAILED, $attempt->status);
        $this->assertSame(MaterialProfileAttemptErrorCode::ProviderTimeout, $attempt->errorCodeEnum());
        $this->assertNotNull($attempt->finished_at);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(0, MaterialProfileElement::query()->count());
    }

    public function test_rate_limit_is_recorded_as_provider_http_and_retried(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'rate'], 429, ['Retry-After' => '3'])
                ->push($this->mapSuccessBody(), 200)
                ->push($this->reduceSuccessBody(), 200),
        ]);

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];

        $this->assertNotNull($this->runProfileJobExpectingRetry($job));
        $this->assertSame(
            MaterialProfileAttemptErrorCode::ProviderHttp,
            MaterialProfileAttempt::query()->firstOrFail()->errorCodeEnum(),
        );

        // The same delivery succeeds on its next attempt.
        $this->runProfileJob($job);
        $this->drainProfileJobs();

        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
        $this->assertSame([1, 2, 1], MaterialProfileAttempt::query()
            ->orderBy('profile_attempt_id')
            ->pluck('attempt_number')
            ->map(static fn ($value): int => (int) $value)
            ->all());
    }

    public function test_server_error_is_recorded_as_provider_http(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $this->startProfileAnalysis($this->user, $this->material);
        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $this->assertSame(
            MaterialProfileAttemptErrorCode::ProviderHttp,
            MaterialProfileAttempt::query()->firstOrFail()->errorCodeEnum(),
        );
    }

    public function test_malformed_output_is_recorded_as_schema_invalid(): void
    {
        Http::fake(['*' => Http::response(GeminiProfileFakeResponses::rawText('bukan json sama sekali'), 200)]);

        $this->startProfileAnalysis($this->user, $this->material);
        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $this->assertSame(
            MaterialProfileAttemptErrorCode::SchemaInvalid,
            MaterialProfileAttempt::query()->firstOrFail()->errorCodeEnum(),
        );
        $this->assertSame(0, MaterialProfileElement::query()->count());
    }

    public function test_retry_uses_the_next_attempt_number_and_the_same_execution_token(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];
        $token = $job->stepExecutionToken;

        $this->runProfileJobExpectingRetry($job);
        $this->runProfileJobExpectingRetry($job);

        $attempts = MaterialProfileAttempt::query()->orderBy('attempt_number')->get();
        $this->assertSame([1, 2], $attempts->pluck('attempt_number')->map(static fn ($v): int => (int) $v)->all());
        $this->assertSame($token, (string) $this->mapStep($version, 0)->fresh()->step_execution_token);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $this->mapStep($version, 0)->fresh()->status);
    }

    public function test_three_attempts_are_the_maximum_and_exhaustion_fails_once(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];

        $this->assertNotNull($this->runProfileJobExpectingRetry($job));
        $this->assertNotNull($this->runProfileJobExpectingRetry($job));
        $this->assertNull($this->runProfileJobExpectingRetry($job), 'The final attempt fails terminally instead of retrying.');

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertNotNull($version->failed_at);
        $failedAt = $version->failed_at->toIso8601String();

        $this->assertSame(3, MaterialProfileAttempt::query()->count());
        $this->assertSame(3, MaterialProfileAttempt::query()
            ->where('status', MaterialProfileAttemptStatus::FAILED)
            ->count());
        $failedStep = $this->mapStep($version, 0)->fresh();
        $this->assertSame(MaterialProfileStepStatus::FAILED, $failedStep->status);
        $this->assertNull($failedStep->lease_expires_at);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $failedStep->error_code);

        // Later Steps are aborted through the B1 terminal-abort error code.
        $reduce = $this->reduceStepOf($version)->fresh();
        $this->assertSame(MaterialProfileStepStatus::FAILED, $reduce->status);
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $reduce->error_code);
        $this->assertNull($reduce->lease_expires_at);
        Http::assertSentCount(3);

        // A fourth delivery is a no-op: no HTTP, no Attempt, no second failure.
        $this->runProfileJobExpectingRetry($job);
        Http::assertSentCount(3);
        $this->assertSame(3, MaterialProfileAttempt::query()->count());
        $this->assertSame($failedAt, $version->fresh()->failed_at->toIso8601String());
        $this->assertSame(0, MaterialProfileElement::query()->count());
    }

    public function test_permanent_failure_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => 'permission denied'], 403)]);

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;

        $this->assertNull(
            $this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]),
            'A permanent provider rejection must not ask the queue for another attempt.',
        );

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertSame(1, MaterialProfileAttempt::query()->count());
        $this->assertSame(
            MaterialProfileAttemptErrorCode::ProviderHttp,
            MaterialProfileAttempt::query()->firstOrFail()->errorCodeEnum(),
        );
        $this->assertSame(0, MaterialProfileElement::query()->count());
        Http::assertSentCount(1);
    }

    public function test_missing_api_key_fails_the_workflow_without_any_http_call(): void
    {
        config(['material_profile.api_key' => null]);
        Http::fake();

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $this->assertNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(1, MaterialProfileAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_owner_facing_error_stays_generic_for_provider_failures(): void
    {
        Http::fake(['*' => Http::response(['error' => 'permission denied for project 12345'], 403)]);

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]);

        $version = $version->fresh();
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertStringNotContainsString('12345', (string) $version->error_message);
        $this->assertStringNotContainsString('403', (string) $version->error_message);
    }

    private function fakeSuccessfulProvider(): void
    {
        Http::fake(fn (Request $request) => str_contains($this->promptText($request), '<<<SUMMARIES>>>')
            ? Http::response($this->reduceSuccessBody(), 200)
            : Http::response($this->mapSuccessBody(), 200));
    }

    private function promptText(Request $request): string
    {
        return (string) ($request->data()['contents'][0]['parts'][0]['text'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSuccessBody(): array
    {
        return GeminiProfileFakeResponses::success(GeminiProfileFakeResponses::mapPayload([
            GeminiProfileFakeResponses::observation(
                'topic',
                'Fotosintesis',
                mb_substr(self::CONTENT, 0, 12, 'UTF-8'),
                0,
                12,
            ),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function reduceSuccessBody(): array
    {
        return GeminiProfileFakeResponses::success(GeminiProfileFakeResponses::reducePayload([
            GeminiProfileFakeResponses::element('topic', 'Fotosintesis'),
            GeminiProfileFakeResponses::element('objective', 'Menjelaskan tahapan fotosintesis'),
            GeminiProfileFakeResponses::element('indicator', 'Menyebutkan tiga faktor pendukung'),
        ]));
    }
}
