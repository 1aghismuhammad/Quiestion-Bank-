<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\BeginBlueprintAttempt;
use App\Actions\QuestionBlueprints\ClaimBlueprintAiFill;
use App\Actions\QuestionBlueprints\QueueBlueprintAiFill;
use App\Actions\QuestionBlueprints\RunBlueprintAiFill;
use App\Actions\QuestionBlueprints\UpdateBlueprintDraft;
use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillRequest;
use App\Data\QuestionBlueprints\BlueprintFillResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintClaimOutcome;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
use App\Enums\DifficultyLevel;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\FillQuestionBlueprintJob;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\QuestionBlueprintAttempt;
use App\Models\QuestionBlueprintRow;
use App\Models\User;
use App\Services\AI\BlueprintFillPromptBuilder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class AdvancedBlueprintAiFillTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Queue::fake([FillQuestionBlueprintJob::class]);
        config([
            'question_blueprint.api_key' => 'test-key',
            'question_blueprint.primary_model' => 'gemini-3.5-flash-lite',
            'question_blueprint.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_advanced_target_fifteen_stays_draft_and_uses_fill_v2(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk pengisian AI lanjutan.',
        ]);
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = fn (BlueprintFillRequest $request): BlueprintFillResult => $this->fillResult($request, 15, true);

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            'Kisi AI lanjutan',
            null,
            BlueprintMode::Advanced,
            15,
        );

        $this->assertSame(15, (int) $blueprint->ai_fill_requested_total);
        $this->assertSame(BlueprintMode::Advanced, $blueprint->mode);
        Queue::assertPushed(FillQuestionBlueprintJob::class, 1);

        $this->drainBlueprintJobs();

        $blueprint->refresh()->load('rows');
        $this->assertSame(BlueprintAiFillStatus::Succeeded, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(15, (int) $blueprint->rows->sum('requested_count'));
        $this->assertLessThanOrEqual(5, $blueprint->rows->count());
        $this->assertTrue($blueprint->rows->every(fn ($row): bool => (int) $row->requested_count <= 10));
        $this->assertSame(
            BlueprintFillPromptBuilder::V2,
            QuestionBlueprintAttempt::query()->first()?->prompt_version,
        );
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertFalse(Schema::hasColumn('question_blueprint_attempts', 'prompt'));
        $this->assertFalse(Schema::hasColumn('question_blueprint_attempts', 'provider_body'));
        $this->assertSame(BlueprintFillPromptBuilder::V2, $fake->requests[0]->promptVersion);
        $this->assertSame(15, $fake->requests[0]->requestedTotal);

        $edited = $this->app->make(UpdateBlueprintDraft::class)->handle(
            $user,
            $blueprint,
            $blueprint->title,
            $blueprint->assessment_type,
            $this->ensureMappedSources($material, [
                $this->sampleRow(8, DifficultyLevel::EASY),
                array_merge($this->sampleRow(4, DifficultyLevel::HOTS), ['topic' => 'Diedit']),
            ]),
            BlueprintMode::Advanced,
        );

        $this->assertSame(12, (int) $edited->rows->sum('requested_count'));
        $this->assertSame(BlueprintLifecycleStatus::Draft, $edited->lifecycle_status);
        $this->assertSame(15, (int) $edited->ai_fill_requested_total);
    }

    public function test_simple_blank_prompt_identity_fails_before_http(): void
    {
        $this->assertInvalidPromptConfigurationFailsBeforeHttp(
            BlueprintMode::Simple,
            null,
            ['question_blueprint.prompt_version' => ''],
        );
    }

    public function test_advanced_blank_prompt_identity_fails_before_http(): void
    {
        $this->assertInvalidPromptConfigurationFailsBeforeHttp(
            BlueprintMode::Advanced,
            15,
            ['question_blueprint.advanced_prompt_version' => ''],
        );
    }

    public function test_simple_unsupported_prompt_identity_fails_before_http(): void
    {
        $this->assertInvalidPromptConfigurationFailsBeforeHttp(
            BlueprintMode::Simple,
            null,
            ['question_blueprint.prompt_version' => 'blueprint-fill-runtime'],
        );
    }

    public function test_advanced_unsupported_prompt_identity_fails_before_http(): void
    {
        $this->assertInvalidPromptConfigurationFailsBeforeHttp(
            BlueprintMode::Advanced,
            15,
            ['question_blueprint.advanced_prompt_version' => 'blueprint-fill-runtime'],
        );
    }

    public function test_simple_configured_as_v2_fails_before_http(): void
    {
        $this->assertInvalidPromptConfigurationFailsBeforeHttp(
            BlueprintMode::Simple,
            null,
            ['question_blueprint.prompt_version' => BlueprintFillPromptBuilder::V2],
        );
    }

    public function test_advanced_configured_as_v1_fails_before_http(): void
    {
        $this->assertInvalidPromptConfigurationFailsBeforeHttp(
            BlueprintMode::Advanced,
            15,
            ['question_blueprint.advanced_prompt_version' => BlueprintFillPromptBuilder::V1],
        );
    }

    public function test_malformed_total_fails_atomically_without_rows_or_usage(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = fn (BlueprintFillRequest $request): BlueprintFillResult => $this->fillResult($request, 16, true);

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            null,
            null,
            BlueprintMode::Advanced,
            15,
        );
        $this->drainBlueprintJobs();

        $blueprint->refresh();
        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(0, $blueprint->rows()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertSame(BlueprintAttemptStatus::Failed, QuestionBlueprintAttempt::query()->first()?->status);
    }

    public function test_duplicate_same_token_invalid_prompt_does_not_terminalize_started_attempt(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        Http::fake();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $job = Queue::pushed(FillQuestionBlueprintJob::class)->first();
        $this->assertInstanceOf(FillQuestionBlueprintJob::class, $job);

        $claim = $this->app->make(ClaimBlueprintAiFill::class);
        $first = $claim->handle($job->blueprintId, $job->workflowToken, $job->stepExecutionToken);
        $this->assertTrue($first->shouldRun());
        $this->assertSame(BlueprintClaimOutcome::Claimed, $first->outcome);

        $attempt = $this->app->make(BeginBlueprintAttempt::class)->handle(
            $job->blueprintId,
            $job->workflowToken,
            $job->stepExecutionToken,
            'fake_blueprint',
            'fake-model',
            BlueprintFillPromptBuilder::V1,
        );
        $this->assertNotNull($attempt);
        $this->assertSame(BlueprintAttemptStatus::Started, $attempt->status);

        config(['question_blueprint.prompt_version' => 'blueprint-fill-runtime']);

        $duplicate = $claim->handle($job->blueprintId, $job->workflowToken, $job->stepExecutionToken);
        $this->assertFalse($duplicate->shouldRun());
        $this->assertSame(BlueprintClaimOutcome::Duplicate, $duplicate->outcome);

        $this->app->make(RunBlueprintAiFill::class)->handle(
            $job->blueprintId,
            $job->workflowToken,
            $job->stepExecutionToken,
        );

        $blueprint->refresh();
        $this->assertSame(0, $fake->calls);
        $this->assertSame([], $fake->requests);
        Http::assertNothingSent();
        $this->assertSame(BlueprintAiFillStatus::Processing, $blueprint->ai_fill_status);
        $this->assertSame($job->workflowToken, $blueprint->workflow_token);
        $this->assertSame($job->stepExecutionToken, $blueprint->step_execution_token);
        $this->assertSame(1, QuestionBlueprintAttempt::query()->count());
        $this->assertSame(BlueprintAttemptStatus::Started, $attempt->fresh()->status);
        $this->assertSame(0, QuestionBlueprintRow::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertNull($blueprint->error_code);
    }

    public function test_stale_job_with_invalid_prompt_does_not_terminalize_newer_workflow(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        Http::fake();

        $first = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            null,
            null,
            BlueprintMode::Advanced,
            15,
        );
        $stale = Queue::pushed(FillQuestionBlueprintJob::class)->first();
        $this->assertInstanceOf(FillQuestionBlueprintJob::class, $stale);

        $first->update([
            'ai_fill_status' => BlueprintAiFillStatus::Failed,
            'workflow_token' => null,
            'step_execution_token' => null,
        ]);

        $retried = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            $first->fresh(),
            null,
            null,
            BlueprintMode::Advanced,
            20,
        );
        $workflowToken = (string) $retried->workflow_token;
        $stepToken = (string) $retried->step_execution_token;

        config(['question_blueprint.advanced_prompt_version' => 'blueprint-fill-runtime']);

        $revoked = $this->app->make(ClaimBlueprintAiFill::class)->handle(
            $stale->blueprintId,
            $stale->workflowToken,
            $stale->stepExecutionToken,
        );
        $this->assertFalse($revoked->shouldRun());
        $this->assertSame(BlueprintClaimOutcome::Revoked, $revoked->outcome);

        $stale->handle($this->app->make(RunBlueprintAiFill::class));

        $retried->refresh();
        $this->assertSame(0, $fake->calls);
        $this->assertSame([], $fake->requests);
        Http::assertNothingSent();
        $this->assertSame(BlueprintAiFillStatus::Queued, $retried->ai_fill_status);
        $this->assertSame($workflowToken, (string) $retried->workflow_token);
        $this->assertSame($stepToken, (string) $retried->step_execution_token);
        $this->assertSame(20, (int) $retried->ai_fill_requested_total);
        $this->assertSame(0, QuestionBlueprintAttempt::query()->count());
        $this->assertSame(0, QuestionBlueprintRow::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertNull($retried->error_code);
    }

    public function test_stale_job_cannot_consume_a_newer_target(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = fn (BlueprintFillRequest $request): BlueprintFillResult => $this->fillResult(
            $request,
            (int) $request->requestedTotal,
            true,
        );

        $first = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            null,
            null,
            BlueprintMode::Advanced,
            15,
        );
        $stale = Queue::pushed(FillQuestionBlueprintJob::class)->first();
        $this->assertInstanceOf(FillQuestionBlueprintJob::class, $stale);

        $first->update([
            'ai_fill_status' => BlueprintAiFillStatus::Failed,
            'workflow_token' => null,
            'step_execution_token' => null,
        ]);

        $retried = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            $first->fresh(),
            null,
            null,
            BlueprintMode::Advanced,
            20,
        );

        $this->assertSame(20, (int) $retried->ai_fill_requested_total);
        $this->assertNotSame($stale->workflowToken, $retried->workflow_token);

        $stale->handle($this->app->make(RunBlueprintAiFill::class));
        $this->assertSame(0, $fake->calls);
        $this->assertSame(20, (int) $retried->fresh()->ai_fill_requested_total);
        $this->assertSame(0, $retried->fresh()->rows()->count());

        $this->drainBlueprintJobs();
        $retried->refresh()->load('rows');
        $this->assertSame(1, $fake->calls);
        $this->assertSame(20, (int) $retried->rows->sum('requested_count'));
        $this->assertSame(20, $fake->requests[0]->requestedTotal);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $retried->lifecycle_status);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_provenance_invalid_advanced_output_fails_without_rows_or_usage(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = function (BlueprintFillRequest $request): BlueprintFillResult {
            $valid = $this->fillResult($request, 15, true);

            return new BlueprintFillResult(
                [
                    new BlueprintFillCandidate(
                        $valid->candidates[0]->objective,
                        $valid->candidates[0]->topic,
                        $valid->candidates[0]->indicator,
                        $valid->candidates[0]->cognitiveLevel,
                        $valid->candidates[0]->difficulty,
                        15,
                        [['context_ref' => 'missing', 'excerpt_start' => 0, 'excerpt_end' => 1]],
                    ),
                ],
                $valid->metadata,
            );
        };

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            null,
            null,
            BlueprintMode::Advanced,
            15,
        );
        $this->drainBlueprintJobs();

        $blueprint->refresh();
        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(0, $blueprint->rows()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertSame(BlueprintAttemptStatus::Failed, QuestionBlueprintAttempt::query()->first()?->status);
    }

    public function test_retry_ignores_posted_simple_mode_and_keeps_workflow_target(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = fn (BlueprintFillRequest $request): BlueprintFillResult => $this->fillResult(
            $request,
            (int) $request->requestedTotal,
            true,
        );

        $first = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            null,
            null,
            BlueprintMode::Advanced,
            15,
        );
        $first->update([
            'ai_fill_status' => BlueprintAiFillStatus::Failed,
            'workflow_token' => null,
            'step_execution_token' => null,
        ]);

        $retried = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            $first->fresh(),
            null,
            null,
            BlueprintMode::Simple,
            null,
        );

        $this->assertSame(BlueprintMode::Advanced, $retried->mode);
        $this->assertSame(15, (int) $retried->ai_fill_requested_total);

        $this->drainBlueprintJobs();
        $retried->refresh()->load('rows');

        $this->assertSame(1, $fake->calls);
        $this->assertSame(BlueprintFillPromptBuilder::V2, $fake->requests[0]->promptVersion);
        $this->assertSame(15, (int) $retried->rows->sum('requested_count'));
        $this->assertSame(
            BlueprintFillPromptBuilder::V2,
            QuestionBlueprintAttempt::query()->first()?->prompt_version,
        );
        $this->assertSame(BlueprintLifecycleStatus::Draft, $retried->lifecycle_status);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_ai_fill_requested_total_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            null,
            null,
            BlueprintMode::Advanced,
            15,
        );

        $blueprint->update(['ai_fill_requested_total' => 99]);

        $this->assertSame(15, (int) $blueprint->fresh()->ai_fill_requested_total);
        $this->assertSame(BlueprintMode::Advanced, $blueprint->fresh()->mode);
    }

    public function test_in_flight_fill_rejects_target_and_row_mutation(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            null,
            null,
            BlueprintMode::Advanced,
            15,
        );
        $target = (int) $blueprint->ai_fill_requested_total;
        $token = (string) $blueprint->workflow_token;

        try {
            $this->app->make(UpdateBlueprintDraft::class)->handle(
                $user,
                $blueprint,
                'Tidak boleh saat berjalan',
                $blueprint->assessment_type,
                $this->ensureMappedSources($material, $this->advancedRows()),
                BlueprintMode::Advanced,
            );
            $this->fail('In-flight Advanced fill must reject draft updates.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::InFlightExists, $exception->errorCode);
        }

        $blueprint->refresh();
        $this->assertSame($target, (int) $blueprint->ai_fill_requested_total);
        $this->assertSame($token, (string) $blueprint->workflow_token);
        $this->assertSame(0, $blueprint->rows()->count());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function advancedRows(): array
    {
        return [
            $this->sampleRow(10, DifficultyLevel::EASY),
            array_merge($this->sampleRow(5, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ];
    }

    private function fillResult(BlueprintFillRequest $request, int $total, bool $mixed): BlueprintFillResult
    {
        $context = $request->contexts[0] ?? null;
        $end = $context === null ? 0 : min(8, mb_strlen($context->excerpt, 'UTF-8'));
        $contexts = $context === null || $end < 1 ? [] : [[
            'context_ref' => $context->ref,
            'excerpt_start' => 0,
            'excerpt_end' => $end,
        ]];

        $first = min(10, $total);
        $second = $total - $first;
        $candidates = [
            new BlueprintFillCandidate(
                'Peserta mampu menjelaskan konsep utama materi.',
                'Konsep utama',
                'Peserta menyebutkan contoh penerapan.',
                'understand',
                $mixed ? 'easy' : 'medium',
                $first,
                $contexts,
            ),
        ];

        if ($second > 0) {
            $candidates[] = new BlueprintFillCandidate(
                'Peserta mampu menganalisis penerapan lanjutan.',
                'Penerapan lanjutan',
                'Peserta memberi contoh analisis.',
                'analyze',
                $mixed ? 'hots' : 'medium',
                $second,
                $contexts,
            );
        }

        return new BlueprintFillResult(
            $candidates,
            new BlueprintProviderAttemptMetadata(
                'fake_blueprint',
                $request->model,
                $request->promptVersion,
                11,
                22,
                33,
                40,
            ),
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    private function assertInvalidPromptConfigurationFailsBeforeHttp(
        BlueprintMode $mode,
        ?int $target,
        array $config,
    ): void {
        $user = User::factory()->create();

        if ($mode === BlueprintMode::Advanced) {
            $this->grantActivePro($user);
        }

        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        Http::fake();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            'Kisi AI konfigurasi',
            null,
            $mode,
            $target,
        );

        $this->assertNotSame('', (string) $blueprint->workflow_token);
        $this->assertSame(BlueprintAiFillStatus::Queued, $blueprint->ai_fill_status);

        config($config);
        $this->drainBlueprintJobs();

        $blueprint->refresh();
        $this->assertSame(0, $fake->calls);
        $this->assertSame([], $fake->requests);
        Http::assertNothingSent();
        $this->assertSame(0, QuestionBlueprintAttempt::query()->count());
        $this->assertSame(0, QuestionBlueprintRow::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(BlueprintErrorCode::ValidationFailed->value, $blueprint->error_code);
        $this->assertNull($blueprint->workflow_token);
        $this->assertNull($blueprint->step_execution_token);
    }
}
