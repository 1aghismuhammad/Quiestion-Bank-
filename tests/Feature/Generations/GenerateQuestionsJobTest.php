<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\BeginGenerationAttempt;
use App\Actions\Generations\ClaimGenerationExecution;
use App\Actions\Generations\FinishGenerationAttempt;
use App\Actions\Generations\RunQuestionGeneration;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Enums\QuestionType;
use App\Enums\UsageStatus;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\TestCase;

class GenerateQuestionsJobTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config([
            'generation.api_key' => 'test-key',
            'generation.primary_model' => 'gemini-3.5-flash-lite',
            'generation.fallback_model' => 'gemini-3.7-flash',
            'generation.prompt_version' => 'mcq-v1',
            'generation.backoff_seconds' => [0, 0],
            'generation.max_questions' => 10,
        ]);
    }

    public function test_new_generation_has_attempt_number_zero(): void
    {
        $generation = $this->startGeneration(User::factory()->create());

        $this->assertSame(0, $generation->attempt_number);
        $this->assertSame(0, AiGenerationAttempt::query()->count());
    }

    public function test_before_first_http_attempt_row_exists_and_attempt_number_is_one(): void
    {
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
        $seen = false;

        Http::fake(function () use ($generation, &$seen) {
            $seen = true;
            $this->assertSame(1, $generation->fresh()->attempt_number);
            $attempt = AiGenerationAttempt::query()->first();
            $this->assertNotNull($attempt);
            $this->assertSame(1, $attempt->attempt_number);
            $this->assertSame(GenerationAttemptStatus::STARTED, $attempt->status);
            $this->assertSame('mcq-v1', $attempt->prompt_version);

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1)), 200);
        });

        $this->runJob($generation);

        $this->assertTrue($seen);
        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::CHARGED, $generation->fresh()->usageLog->status);
    }

    public function test_same_execution_token_processing_may_resume(): void
    {
        $generation = $this->queuedProcessing($this->validPartial(9), attemptNumber: 1);
        $token = (string) $generation->execution_token;
        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 1,
            'status' => GenerationAttemptStatus::SUCCEEDED,
            'requested_count' => 10,
            'accepted_count' => 9,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Repair')),
                200,
            ),
        ]);

        $this->runJob($generation, $token);

        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
        $this->assertSame(2, AiGenerationAttempt::query()->count());
        $this->assertSame(1, AiGeneration::query()->count());
        $this->assertSame(1, AiUsageLog::query()->count());
        Http::assertSentCount(1);
    }

    public function test_different_execution_token_processing_does_not_call_provider(): void
    {
        $generation = $this->queuedProcessing();
        Http::fake();

        $this->runJob($generation, (string) Str::uuid());

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::PROCESSING, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $generation->fresh()->usageLog->status);
        $this->assertSame(0, $generation->fresh()->attempt_number);
    }

    public function test_stale_job_failed_cannot_fail_or_release_another_tokens_generation(): void
    {
        $generation = $this->queuedProcessing();
        $stale = new GenerateQuestionsJob((int) $generation->generation_id, (string) Str::uuid());

        $stale->failed(new RuntimeException('worker died'));

        $this->assertSame(GenerationStatus::PROCESSING, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $generation->fresh()->usageLog->status);
        $this->assertNull($generation->fresh()->failed_at);
    }

    public function test_stale_execution_cannot_finish_attempt_or_persist_partial_results(): void
    {
        $generation = $this->queuedProcessing();
        $owner = (string) $generation->execution_token;
        $attempt = $this->app->make(BeginGenerationAttempt::class)->handle(
            (int) $generation->generation_id,
            $owner,
            GenerationAttemptPurpose::INITIAL,
            5,
            'gemini-3.5-flash-lite',
            'mcq-v1',
        );

        $generation->execution_token = (string) Str::uuid();
        $generation->save();

        $this->expectException(StaleGenerationExecutionException::class);
        $this->app->make(FinishGenerationAttempt::class)->handle(
            (int) $generation->generation_id,
            $owner,
            (int) $attempt->attempt_id,
            GenerationAttemptStatus::SUCCEEDED,
            1,
        );
    }

    public function test_stale_execution_cannot_persist_attempt_finish_and_partial_result_together(): void
    {
        $generation = $this->queuedProcessing();
        $owner = (string) $generation->execution_token;
        $attempt = $this->app->make(BeginGenerationAttempt::class)->handle(
            (int) $generation->generation_id,
            $owner,
            GenerationAttemptPurpose::INITIAL,
            5,
            'gemini-3.5-flash-lite',
            'mcq-v1',
        );

        $generation->execution_token = (string) Str::uuid();
        $generation->save();

        $question = ValidatedMcqQuestion::fromArray(GeminiFakeResponses::question('Kept'));

        try {
            $this->app->make(FinishGenerationAttempt::class)->handle(
                (int) $generation->generation_id,
                $owner,
                (int) $attempt->attempt_id,
                GenerationAttemptStatus::SUCCEEDED,
                1,
                null,
                null,
                new ValidatedMcqSet([$question]),
            );
            $this->fail('Expected StaleGenerationExecutionException');
        } catch (StaleGenerationExecutionException) {
            $this->assertNull($generation->fresh()->result_json);
            $this->assertSame(GenerationAttemptStatus::STARTED, $attempt->fresh()->status);
            $this->assertNull($attempt->fresh()->finished_at);
        }
    }

    public function test_attempt_prompt_version_matches_builder_version_used_for_the_call(): void
    {
        config(['generation.prompt_version' => 'mcq-runtime']);
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::questions(1)),
                200,
            ),
        ]);

        $this->runJob($generation);

        $this->assertSame('mcq-runtime', AiGenerationAttempt::query()->first()->prompt_version);
    }

    public function test_queued_under_v1_then_executed_with_v2_records_v2(): void
    {
        config(['generation.prompt_version' => 'mcq-v1']);
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
        config(['generation.prompt_version' => 'mcq-v2']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::questions(1)),
                200,
            ),
        ]);

        $this->runJob($generation);

        $this->assertSame('mcq-v2', AiGenerationAttempt::query()->first()->prompt_version);
        $this->assertFalse(Schema::hasColumn('ai_generations', 'prompt_version'));
    }

    public function test_generation_queue_retry_after_does_not_change_extraction_connection(): void
    {
        $job = new GenerateQuestionsJob(99);

        $this->assertSame(90, (int) config('queue.connections.database.retry_after'));
        $this->assertSame(360, (int) config('queue.connections.database-generation.retry_after'));
        $this->assertSame('database-generation', $job->connection);
        $this->assertSame('question-generation', $job->queue);
        $this->assertSame(270, $job->timeout);
        $this->assertSame(3, $job->tries);
        $this->assertFalse($job->failOnTimeout);
    }

    public function test_legacy_null_output_language_does_not_call_gemini_and_releases(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->withoutOutputLanguage()->create([
            'question_count' => 1,
            'attempt_number' => 0,
        ]);
        AiUsageLog::factory()->for($generation, 'generation')->create(['user_id' => $user->id]);
        Http::fake();

        $this->runJob($generation);

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
        $this->assertSame(GenerationErrorCode::MissingOutputLanguage->value, $generation->fresh()->error_code);
        $this->assertSame(0, AiGenerationAttempt::query()->count());
    }

    public function test_three_started_attempts_plus_job_redelivery_does_not_start_a_fourth_http(): void
    {
        $generation = $this->queuedProcessing();
        foreach ([1, 2, 3] as $number) {
            AiGenerationAttempt::factory()->for($generation, 'generation')->create([
                'attempt_number' => $number,
                'status' => GenerationAttemptStatus::STARTED,
            ]);
        }
        $generation->attempt_number = 3;
        $generation->save();
        Http::fake();

        $this->runJob($generation, (string) $generation->execution_token);

        Http::assertNothingSent();
        $this->assertSame(3, AiGenerationAttempt::query()->count());
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
    }

    public function test_config_override_to_four_still_hard_caps_at_three_provider_calls(): void
    {
        config(['generation.max_provider_attempts' => 4]);
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'down'], 503),
        ]);

        $this->runJob($generation);

        Http::assertSentCount(3);
        $this->assertSame(3, AiGenerationAttempt::query()->count());
        $this->assertSame(0, AiGenerationAttempt::query()->where('attempt_number', '>=', 4)->count());
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
    }

    public function test_resume_after_attempt_two_timeout_uses_fallback_model(): void
    {
        $generation = $this->queuedProcessing(null, attemptNumber: 2);
        $token = (string) $generation->execution_token;
        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 1,
            'status' => GenerationAttemptStatus::FAILED,
            'safe_error_code' => GenerationErrorCode::ProviderTimeout->value,
        ]);
        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 2,
            'status' => GenerationAttemptStatus::FAILED,
            'safe_error_code' => GenerationErrorCode::ProviderTimeout->value,
        ]);

        Http::fake(function ($request) {
            $this->assertStringContainsString('models/gemini-3.7-flash', $request->url());

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(10)), 200);
        });

        $this->runJob($generation, $token);

        $attempt = AiGenerationAttempt::query()->where('attempt_number', 3)->first();
        $this->assertNotNull($attempt);
        $this->assertSame('gemini-3.7-flash', $attempt->model);
        Http::assertSentCount(1);
        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
    }

    public function test_resume_after_attempt_two_auth_fails_closed_without_http(): void
    {
        $generation = $this->queuedProcessing(null, attemptNumber: 2);
        $token = (string) $generation->execution_token;
        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 1,
            'status' => GenerationAttemptStatus::FAILED,
            'safe_error_code' => GenerationErrorCode::ProviderTimeout->value,
        ]);
        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 2,
            'status' => GenerationAttemptStatus::FAILED,
            'safe_error_code' => GenerationErrorCode::Auth->value,
        ]);
        Http::fake();

        $this->runJob($generation, $token);

        Http::assertNothingSent();
        $this->assertSame(2, AiGenerationAttempt::query()->count());
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
        $this->assertSame(GenerationErrorCode::Auth->value, $generation->fresh()->error_code);
    }

    public function test_partial_attempt_persistence_is_atomic_and_resume_requests_exactly_one(): void
    {
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 10);
        $calls = 0;

        Http::fake(function ($request) use ($generation, &$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(9)), 200);
            }

            $fresh = $generation->fresh();
            $this->assertCount(9, $fresh->result_json);
            $firstAttempt = AiGenerationAttempt::query()
                ->where('generation_id', $fresh->generation_id)
                ->where('attempt_number', 1)
                ->first();
            $this->assertNotNull($firstAttempt);
            $this->assertSame(GenerationAttemptStatus::SUCCEEDED, $firstAttempt->status);
            $this->assertSame(9, $firstAttempt->accepted_count);
            $this->assertSame(GenerationErrorCode::IncompleteOutput->value, $firstAttempt->safe_error_code);
            $this->assertNotNull($firstAttempt->finished_at);

            $userText = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('Requested count: 1', $userText);
            $this->assertStringNotContainsString('Requested count: 10', $userText);

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Repair')), 200);
        });

        $this->runJob($generation);

        $this->assertSame(2, $calls);
        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
        $this->assertCount(10, $generation->fresh()->result_json);
        $this->assertSame(UsageStatus::CHARGED, $generation->fresh()->usageLog->status);
    }

    public function test_persisted_complete_result_finalizes_on_resume_without_http(): void
    {
        $generation = $this->queuedProcessing($this->validPartial(10), attemptNumber: 1);
        $token = (string) $generation->execution_token;
        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 1,
            'status' => GenerationAttemptStatus::SUCCEEDED,
            'requested_count' => 10,
            'accepted_count' => 10,
            'safe_error_code' => null,
        ]);
        Http::fake();

        $this->runJob($generation, $token);

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::CHARGED, $generation->fresh()->usageLog->status);
        $this->assertCount(10, $generation->fresh()->result_json);
        $this->assertSame(1, AiGenerationAttempt::query()->count());
    }

    public function test_legacy_essay_generation_fails_closed_without_http(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create([
            'question_type' => QuestionType::ESSAY,
            'question_count' => 1,
            'attempt_number' => 0,
        ]);
        AiUsageLog::factory()->for($generation, 'generation')->create(['user_id' => $user->id]);
        Http::fake();

        $this->runJob($generation);

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
        $this->assertSame(GenerationErrorCode::UnsupportedQuestionType->value, $generation->fresh()->error_code);
        $this->assertSame(0, AiGenerationAttempt::query()->count());
    }

    public function test_invalid_stored_question_count_fails_closed_without_http(): void
    {
        foreach ([0, 11] as $count) {
            $user = User::factory()->create();
            $material = Material::factory()->text()->for($user)->create();
            $generation = AiGeneration::factory()->for($user)->for($material)->create([
                'question_count' => $count,
                'attempt_number' => 0,
            ]);
            AiUsageLog::factory()->for($generation, 'generation')->create(['user_id' => $user->id]);
            Http::fake();

            $this->runJob($generation);

            Http::assertNothingSent();
            $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
            $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
            $this->assertSame(GenerationErrorCode::InvalidQuestionCount->value, $generation->fresh()->error_code);
            $this->assertSame(0, AiGenerationAttempt::query()->where('generation_id', $generation->generation_id)->count());
        }
    }

    public function test_does_not_back_off_after_the_last_provider_attempt(): void
    {
        Sleep::fake();
        config(['generation.backoff_seconds' => [5, 15]]);
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'down'], 503),
        ]);

        try {
            $this->runJob($generation);

            Sleep::assertSleptTimes(2);
            $this->assertSame(3, AiGenerationAttempt::query()->count());
            $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
            $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
        } finally {
            Sleep::fake(false);
        }
    }

    public function test_targeted_repair_requests_exactly_the_missing_count(): void
    {
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 10);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(GeminiFakeResponses::success(GeminiFakeResponses::questions(9)), 200)
                ->push(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Repair')), 200),
        ]);

        $this->runJob($generation);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('Requested count: 10', $requests[0][0]->data()['contents'][0]['parts'][0]['text']);
        $this->assertStringContainsString('Requested count: 1', $requests[1][0]->data()['contents'][0]['parts'][0]['text']);
        $this->assertStringNotContainsString('Requested count: 10', $requests[1][0]->data()['contents'][0]['parts'][0]['text']);
        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
        $this->assertCount(10, $generation->fresh()->result_json);
        $this->assertSame(GenerationAttemptPurpose::REPAIR, AiGenerationAttempt::query()->where('attempt_number', 2)->first()->purpose);
    }

    public function test_partial_result_survives_same_token_job_resume(): void
    {
        $generation = $this->queuedProcessing($this->validPartial(9), attemptNumber: 1);
        $token = (string) $generation->execution_token;
        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 1,
            'status' => GenerationAttemptStatus::SUCCEEDED,
            'requested_count' => 10,
            'accepted_count' => 9,
            'safe_error_code' => GenerationErrorCode::IncompleteOutput->value,
        ]);

        Http::fake(function ($request) {
            $userText = $request->data()['contents'][0]['parts'][0]['text'] ?? '';
            $this->assertStringContainsString('Requested count: 1', $userText);
            $this->assertStringNotContainsString('Requested count: 10', $userText);

            return Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Fill')),
                200,
            );
        });

        $this->runJob($generation, $token);

        $result = $generation->fresh()->result_json;
        $this->assertCount(10, $result);
        $this->assertSame('Question 1', $result[0]['question']);
        $this->assertSame(UsageStatus::CHARGED, $generation->fresh()->usageLog->status);
        Http::assertSentCount(1);
    }

    public function test_completed_failed_and_cancelled_redelivery_does_not_call_provider(): void
    {
        Http::fake();

        foreach ([GenerationStatus::COMPLETED, GenerationStatus::FAILED, GenerationStatus::CANCELLED] as $status) {
            $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
            $generation->generation_status = $status;
            $generation->save();
            $this->runJob($generation);
        }

        Http::assertNothingSent();
    }

    public function test_oversize_material_fails_without_http_and_releases(): void
    {
        config(['generation.max_material_chars' => 8]);
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'this is definitely too long',
        ]);
        $generation = $this->startGeneration($user, $material, questionCount: 1);
        Http::fake();

        $this->runJob($generation);

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
        $this->assertSame(GenerationErrorCode::MaterialTooLarge->value, $generation->fresh()->error_code);
    }

    public function test_missing_api_key_fails_without_http(): void
    {
        config(['generation.api_key' => '']);
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
        Http::fake();

        $this->runJob($generation);

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
        $this->assertSame(GenerationErrorCode::Configuration->value, $generation->fresh()->error_code);
    }

    public function test_success_finalization_is_idempotent_and_charges_once(): void
    {
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                GeminiFakeResponses::success(GeminiFakeResponses::questions(1)),
                200,
            ),
        ]);

        $token = $this->runJob($generation)->executionToken;
        $this->runJob($generation, $token);

        $this->assertSame(1, AiUsageLog::query()->where('status', UsageStatus::CHARGED->value)->count());
        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
        $this->assertNull($generation->fresh()->failed_at);
        $this->assertNotNull($generation->fresh()->completed_at);
    }

    public function test_claim_of_queued_generation_stores_execution_token(): void
    {
        $generation = $this->startGeneration(User::factory()->create(), questionCount: 1);
        $token = (string) Str::uuid();

        $result = $this->app->make(ClaimGenerationExecution::class)
            ->handle((int) $generation->generation_id, $token);

        $this->assertTrue($result->shouldRun);
        $this->assertSame('claimed', $result->outcome);
        $this->assertSame($token, $generation->fresh()->execution_token);
        $this->assertSame(GenerationStatus::PROCESSING, $generation->fresh()->generation_status);
    }

    public function test_job_does_not_create_question_sets(): void
    {
        $this->assertFalse(Schema::hasTable('question_sets'));
        $this->assertFalse(Schema::hasTable('questions'));
    }

    public function test_start_still_dispatches_on_the_generation_queue(): void
    {
        $generation = $this->startGeneration(User::factory()->create());

        Queue::assertPushed(GenerateQuestionsJob::class, function (GenerateQuestionsJob $job) use ($generation): bool {
            return $job->generationId === (int) $generation->generation_id
                && $job->timeout === 270
                && $job->failOnTimeout === false;
        });
    }

    private function runJob(AiGeneration $generation, ?string $token = null): GenerateQuestionsJob
    {
        $job = new GenerateQuestionsJob((int) $generation->generation_id, $token ?? '');
        $job->handle($this->app->make(RunQuestionGeneration::class));

        return $job;
    }

    /**
     * @param  list<array<string, mixed>>|null  $resultJson
     */
    private function queuedProcessing(?array $resultJson = null, int $attemptNumber = 0): AiGeneration
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user, questionCount: 10);
        $generation->generation_status = GenerationStatus::PROCESSING;
        $generation->execution_token = (string) Str::uuid();
        $generation->started_at = now();
        $generation->attempt_number = $attemptNumber;
        $generation->result_json = $resultJson;
        $generation->save();

        return $generation->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validPartial(int $count): array
    {
        return GeminiFakeResponses::questions($count);
    }
}
