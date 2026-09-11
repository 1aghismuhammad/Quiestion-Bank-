<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\ClaimRunChildExecution;
use App\Actions\GenerationRuns\RecoverStaleGenerationRuns;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\BeginGenerationAttempt;
use App\Actions\Generations\FinishGenerationAttempt;
use App\Actions\Generations\RunQuestionGeneration;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Enums\UsageStatus;
use App\Exceptions\Generations\AttemptBudgetExhaustedException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class GenerationRunThirdCorrectiveQaTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Queue::fake([GenerateQuestionsJob::class]);
        Sleep::fake();
        config([
            'generation.api_key' => 'test-key',
            'generation.primary_model' => 'gemini-3.5-flash-lite',
            'generation.fallback_model' => 'gemini-3.7-flash',
            'generation.prompt_version' => 'mcq-v1',
            'generation.backoff_seconds' => [0, 0],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_two_same_token_claims_before_begin_cannot_open_a_second_attempt_or_http_call(): void
    {
        [$user, $blueprint] = $this->ownerWithBlueprint([$this->sampleRow(1)]);
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Race'))));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->assertInstanceOf(GenerateQuestionsJob::class, $job);

        $claim = $this->app->make(ClaimRunChildExecution::class);
        $firstClaim = $claim->handle($job->generationId, $job->executionToken);
        $secondClaim = $claim->handle($job->generationId, $job->executionToken);
        $this->assertTrue($firstClaim->shouldRun);
        $this->assertTrue($secondClaim->shouldRun);
        $this->assertSame(0, AiGenerationAttempt::query()->count());

        $begin = $this->app->make(BeginGenerationAttempt::class);
        $attempt = $begin->handle(
            $job->generationId,
            $job->executionToken,
            GenerationAttemptPurpose::INITIAL,
            1,
            'fake-model',
            'mcq-v1',
        );
        $this->assertSame(1, AiGenerationAttempt::query()->count());
        $this->assertSame(GenerationAttemptStatus::STARTED, $attempt->status);
        $this->assertSame(1, (int) $attempt->attempt_number);

        $duplicateBegin = false;
        try {
            $begin->handle(
                $job->generationId,
                $job->executionToken,
                GenerationAttemptPurpose::INITIAL,
                1,
                'fake-model',
                'mcq-v1',
            );
        } catch (AttemptBudgetExhaustedException) {
            $this->fail('A live started Attempt must be a duplicate no-op, not attempt-budget exhaustion.');
        } catch (StaleGenerationExecutionException) {
            $duplicateBegin = true;
        }
        $this->assertTrue($duplicateBegin);
        $this->assertSame(1, AiGenerationAttempt::query()->count());
        $this->assertSame(1, (int) AiGeneration::query()->whereKey($job->generationId)->value('attempt_number'));
        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(UsageStatus::RESERVED, $run->fresh()->usageLog->status);
        $this->assertSame(GenerationRunStatus::Processing, $run->fresh()->status);
        $this->assertSame(GenerationAttemptStatus::STARTED, $attempt->fresh()->status);
    }

    public function test_two_claimed_deliveries_issue_at_most_one_provider_call(): void
    {
        [$user, $blueprint] = $this->ownerWithBlueprint([$this->sampleRow(1)]);
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Once'))));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $claim = $this->app->make(ClaimRunChildExecution::class);
        $this->assertTrue($claim->handle($job->generationId, $job->executionToken)->shouldRun);
        $this->assertTrue($claim->handle($job->generationId, $job->executionToken)->shouldRun);

        $runner = $this->app->make(RunQuestionGeneration::class);
        $runner->handle($job->generationId, $job->executionToken);
        $runner->handle($job->generationId, $job->executionToken);

        $this->assertSame(1, Http::recorded()->count());
        $this->assertSame(1, AiGenerationAttempt::query()->count());
        $this->assertNotSame(GenerationAttemptStatus::STARTED, AiGenerationAttempt::query()->first()?->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
    }

    public function test_failed_prior_attempt_permits_one_next_sequential_attempt(): void
    {
        [$user, $blueprint] = $this->ownerWithBlueprint([$this->sampleRow(1)]);
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $token = $job->executionToken;
        $this->app->make(ClaimRunChildExecution::class)->handle($job->generationId, $token);

        $first = $this->app->make(BeginGenerationAttempt::class)->handle(
            $job->generationId,
            $token,
            GenerationAttemptPurpose::INITIAL,
            1,
            'fake-model',
            'mcq-v1',
        );
        $this->app->make(FinishGenerationAttempt::class)->handle(
            $job->generationId,
            $token,
            (int) $first->attempt_id,
            GenerationAttemptStatus::FAILED,
            0,
            null,
            GenerationErrorCode::IncompleteOutput,
        );

        $second = $this->app->make(BeginGenerationAttempt::class)->handle(
            $job->generationId,
            $token,
            GenerationAttemptPurpose::REPAIR,
            1,
            'fake-model',
            'mcq-v1',
        );

        $this->assertSame(2, (int) $second->attempt_number);
        $this->assertSame(GenerationAttemptStatus::STARTED, $second->status);
        $this->assertSame(GenerationAttemptStatus::FAILED, $first->fresh()->status);
        $this->assertSame($token, (string) AiGeneration::query()->whereKey($job->generationId)->value('execution_token'));
        $this->assertSame(2, AiGenerationAttempt::query()->count());
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(UsageStatus::RESERVED, $run->fresh()->usageLog->status);
    }

    public function test_finished_run_child_attempt_cannot_be_rewritten(): void
    {
        [$user, $blueprint] = $this->ownerWithBlueprint([$this->sampleRow(1)]);
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->app->make(ClaimRunChildExecution::class)->handle($job->generationId, $job->executionToken);
        $attempt = $this->app->make(BeginGenerationAttempt::class)->handle(
            $job->generationId,
            $job->executionToken,
            GenerationAttemptPurpose::INITIAL,
            1,
            'fake-model',
            'mcq-v1',
        );
        $accepted = new ValidatedMcqSet([
            ValidatedMcqQuestion::fromArray(GeminiFakeResponses::question('Original stem')),
        ]);
        $this->app->make(FinishGenerationAttempt::class)->handle(
            $job->generationId,
            $job->executionToken,
            (int) $attempt->attempt_id,
            GenerationAttemptStatus::SUCCEEDED,
            1,
            null,
            null,
            $accepted,
        );

        $rewrite = false;
        try {
            $this->app->make(FinishGenerationAttempt::class)->handle(
                $job->generationId,
                $job->executionToken,
                (int) $attempt->attempt_id,
                GenerationAttemptStatus::FAILED,
                99,
                null,
                GenerationErrorCode::JobFailed,
            );
        } catch (StaleGenerationExecutionException) {
            $rewrite = true;
        }

        $this->assertTrue($rewrite);
        $fresh = $attempt->fresh();
        $this->assertSame(GenerationAttemptStatus::SUCCEEDED, $fresh->status);
        $this->assertSame(1, (int) $fresh->accepted_count);
        $this->assertNull($fresh->safe_error_code);
        $this->assertSame(['Original stem'], ValidatedMcqSet::fromStoredJson($fresh->generation->result_json)->questionTexts());
        $this->assertSame(UsageStatus::RESERVED, $run->fresh()->usageLog->status);
        $this->assertSame(GenerationStatus::PROCESSING, AiGeneration::query()->whereKey($job->generationId)->first()?->generation_status);
    }

    public function test_foreign_expired_terminal_and_recovered_begin_are_noops(): void
    {
        [$user, $blueprint] = $this->ownerWithBlueprint([$this->sampleRow(1)]);
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->app->make(ClaimRunChildExecution::class)->handle($job->generationId, $job->executionToken);

        $foreign = false;
        try {
            $this->app->make(BeginGenerationAttempt::class)->handle(
                $job->generationId,
                (string) Str::uuid(),
                GenerationAttemptPurpose::INITIAL,
                1,
                'fake-model',
                'mcq-v1',
            );
        } catch (StaleGenerationExecutionException) {
            $foreign = true;
        }
        $this->assertTrue($foreign);
        $this->assertSame(0, AiGenerationAttempt::query()->count());

        AiGeneration::query()->whereKey($job->generationId)->update(['updated_at' => now()->subHours(2)]);
        $expired = false;
        try {
            $this->app->make(BeginGenerationAttempt::class)->handle(
                $job->generationId,
                $job->executionToken,
                GenerationAttemptPurpose::INITIAL,
                1,
                'fake-model',
                'mcq-v1',
            );
        } catch (StaleGenerationExecutionException) {
            $expired = true;
        }
        $this->assertTrue($expired);
        $this->assertSame(0, AiGenerationAttempt::query()->count());

        $this->app->make(RecoverStaleGenerationRuns::class)->handle();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);

        $recovered = false;
        try {
            $this->app->make(BeginGenerationAttempt::class)->handle(
                $job->generationId,
                $job->executionToken,
                GenerationAttemptPurpose::INITIAL,
                1,
                'fake-model',
                'mcq-v1',
            );
        } catch (StaleGenerationExecutionException) {
            $recovered = true;
        }
        $this->assertTrue($recovered);
        $this->assertSame(0, AiGenerationAttempt::query()->count());
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
    }

    public function test_future_child_does_not_age_while_predecessor_is_processing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 08:00:00'));
        [$user, $blueprint] = $this->ownerWithBlueprint([
            $this->sampleRow(1, DifficultyLevel::MEDIUM),
            array_merge($this->sampleRow(1, DifficultyLevel::MEDIUM), ['topic' => 'Penerapan']),
        ]);
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $children = $run->children()->orderBy('child_index')->get();
        $this->assertNotNull($children[0]->queued_at);
        $this->assertNotEmpty((string) $children[0]->execution_token);
        $this->assertNull($children[1]->queued_at);
        $this->assertNull($children[1]->execution_token);

        $this->app->make(ClaimRunChildExecution::class)->handle(
            (int) $children[0]->generation_id,
            (string) $children[0]->execution_token,
        );

        Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00'));
        AiGeneration::query()->whereKey($children[0]->generation_id)->update(['updated_at' => now()]);

        $this->assertSame(0, $this->app->make(RecoverStaleGenerationRuns::class)->handle());
        $later = $children[1]->fresh();
        $this->assertSame(GenerationStatus::QUEUED, $later->generation_status);
        $this->assertNull($later->queued_at);
        $this->assertNull($later->execution_token);
        $this->assertSame(GenerationRunStatus::Processing, $run->fresh()->status);
        $this->assertSame(UsageStatus::RESERVED, $run->fresh()->usageLog->status);
        $this->assertSame(1, Queue::pushed(GenerateQuestionsJob::class)->count());
    }

    public function test_completing_predecessor_activates_next_child_and_redispatch_preserves_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 08:00:00'));
        [$user, $blueprint] = $this->ownerWithBlueprint([
            $this->sampleRow(1, DifficultyLevel::MEDIUM),
            array_merge($this->sampleRow(1, DifficultyLevel::MEDIUM), ['topic' => 'Penerapan']),
        ]);
        $key = (string) Str::uuid();
        Http::fake(function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success([
                GeminiFakeResponses::question($batch === 1 ? 'First clock stem' : 'Second clock stem'),
            ]));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle($user, $blueprint, OutputLanguage::ID, $key);
        $first = $run->children()->orderBy('child_index')->first();
        $originalToken = (string) $first->execution_token;
        $originalQueuedAt = $first->queued_at?->toDateTimeString();

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:05:00'));
        $this->app->make(StartGenerationRun::class)->handle($user, $blueprint, OutputLanguage::ID, $key);
        $first = $first->fresh();
        $this->assertSame($originalToken, (string) $first->execution_token);
        $this->assertSame($originalQueuedAt, $first->queued_at?->toDateTimeString());
        $this->assertSame($originalToken, Queue::pushed(GenerateQuestionsJob::class)->last()->executionToken);

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:10:00'));
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $children = $run->children()->orderBy('child_index')->get();
        $this->assertSame(GenerationStatus::COMPLETED, $children[0]->generation_status);
        $this->assertSame(GenerationStatus::QUEUED, $children[1]->generation_status);
        $this->assertNotNull($children[1]->queued_at);
        $this->assertSame('2026-09-08 08:10:00', $children[1]->queued_at?->toDateTimeString());
        $this->assertNotEmpty((string) $children[1]->execution_token);
        $nextToken = (string) $children[1]->execution_token;
        $this->assertNotSame($originalToken, $nextToken);

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:12:00'));
        $this->app->make(RecoverStaleGenerationRuns::class)->handle();
        $this->assertSame($nextToken, (string) $children[1]->fresh()->execution_token);
        $this->assertSame('2026-09-08 08:10:00', $children[1]->fresh()->queued_at?->toDateTimeString());

        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(2, Http::recorded()->count());
    }

    public function test_activated_queued_child_expires_and_recovery_does_not_skip_index(): void
    {
        [$user, $blueprint] = $this->ownerWithBlueprint([
            $this->sampleRow(1, DifficultyLevel::MEDIUM),
            array_merge($this->sampleRow(1, DifficultyLevel::MEDIUM), ['topic' => 'Penerapan']),
            array_merge($this->sampleRow(1, DifficultyLevel::MEDIUM), ['topic' => 'Evaluasi']),
        ]);
        Http::fake(fn () => Http::response(GeminiFakeResponses::success([GeminiFakeResponses::question('Only first')])));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $children = $run->children()->orderBy('child_index')->get();
        $this->assertSame(GenerationStatus::COMPLETED, $children[0]->generation_status);
        $this->assertSame(GenerationStatus::QUEUED, $children[1]->generation_status);
        $this->assertSame(GenerationStatus::QUEUED, $children[2]->generation_status);
        $this->assertNull($children[2]->queued_at);
        $this->assertNull($children[2]->execution_token);
        $this->assertSame(2, Queue::pushed(GenerateQuestionsJob::class)->count());
        $this->assertSame((int) $children[1]->generation_id, Queue::pushed(GenerateQuestionsJob::class)->last()->generationId);

        AiGeneration::query()->whereKey($children[1]->generation_id)->update(['queued_at' => now()->subHours(2)]);
        $this->app->make(RecoverStaleGenerationRuns::class)->handle();

        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $final = $run->children()->orderBy('child_index')->get();
        $this->assertSame(GenerationStatus::COMPLETED, $final[0]->generation_status);
        $this->assertSame(GenerationStatus::FAILED, $final[1]->generation_status);
        $this->assertSame(GenerationStatus::FAILED, $final[2]->generation_status);
        $this->assertSame(2, Queue::pushed(GenerateQuestionsJob::class)->count());
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: User, 1: QuestionBlueprint}
     */
    private function ownerWithBlueprint(array $rows): array
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);

        return [$user, $this->confirmDraft($user, $this->createDraft($user, $material, $rows))];
    }
}
