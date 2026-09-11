<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\BeginBlueprintAttempt;
use App\Actions\QuestionBlueprints\ClaimBlueprintAiFill;
use App\Actions\QuestionBlueprints\QueueBlueprintAiFill;
use App\Actions\QuestionBlueprints\RecoverStaleBlueprintFills;
use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\FillQuestionBlueprintJob;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class BlueprintAiFillTest extends TestCase
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

    public function test_ai_fill_keeps_draft_and_does_not_write_usage(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk pengisian AI.',
        ]);
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)
            ->handle($user, $material);

        $this->assertSame(BlueprintAiFillStatus::Queued, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        Queue::assertPushed(FillQuestionBlueprintJob::class, 1);

        $this->drainBlueprintJobs();

        $blueprint->refresh();
        $this->assertSame(BlueprintAiFillStatus::Succeeded, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(1, $blueprint->rows()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertSame(1, QuestionBlueprintAttempt::query()->where('status', BlueprintAttemptStatus::Succeeded)->count());
    }

    public function test_same_token_does_not_create_a_second_attempt(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)
            ->handle($user, $material);

        $job = Queue::pushed(FillQuestionBlueprintJob::class)->first();
        $this->assertInstanceOf(FillQuestionBlueprintJob::class, $job);

        $claim = $this->app->make(ClaimBlueprintAiFill::class);
        $this->assertTrue($claim->handle($job->blueprintId, $job->workflowToken, $job->stepExecutionToken)->shouldRun());

        $this->app->make(BeginBlueprintAttempt::class)->handle(
            $job->blueprintId,
            $job->workflowToken,
            $job->stepExecutionToken,
            'fake_blueprint',
            'fake-model',
            'blueprint-fill-v1',
        );

        $duplicate = $claim->handle($job->blueprintId, $job->workflowToken, $job->stepExecutionToken);

        $this->assertFalse($duplicate->shouldRun());
        $this->assertSame(1, QuestionBlueprintAttempt::query()->count());
    }

    public function test_throttle_rejects_fourth_fill_in_an_hour(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();
        $queue = $this->app->make(QueueBlueprintAiFill::class);

        foreach (range(1, 3) as $ignored) {
            $created = $queue->handle($user, $material);
            $created->update([
                'ai_fill_status' => BlueprintAiFillStatus::Failed,
                'workflow_token' => null,
                'step_execution_token' => null,
            ]);
        }

        try {
            $queue->handle($user, $material);
            $this->fail('Fourth fill must be throttled.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ThrottleExceeded, $exception->errorCode);
        }

        $this->assertSame(3, QuestionBlueprint::query()->count());
    }

    public function test_owner_retry_mints_new_tokens_on_the_same_draft(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $blueprint = $this->app->make(QueueBlueprintAiFill::class)
            ->handle($user, $material);

        $firstToken = $blueprint->workflow_token;
        $blueprint->update([
            'ai_fill_status' => BlueprintAiFillStatus::Failed,
            'workflow_token' => null,
            'step_execution_token' => null,
        ]);

        $retried = $this->app->make(QueueBlueprintAiFill::class)
            ->handle($user, $material, $blueprint->fresh());

        $this->assertSame($blueprint->blueprint_id, $retried->blueprint_id);
        $this->assertNotSame($firstToken, $retried->workflow_token);
        $this->assertSame(BlueprintAiFillStatus::Queued, $retried->ai_fill_status);
    }

    public function test_missing_profile_does_not_call_the_blueprint_provider(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $fake = $this->fakeBlueprintProvider();

        try {
            $this->app->make(QueueBlueprintAiFill::class)
                ->handle($user, $material);
            $this->fail('AI fill must require a ready profile.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ProfileRequired, $exception->errorCode);
        }

        $this->assertSame(0, $fake->calls);
        $this->assertSame(0, QuestionBlueprint::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_one_in_flight_fill_per_material(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $queue = $this->app->make(QueueBlueprintAiFill::class);

        $queue->handle($user, $material);

        try {
            $queue->handle($user, $material);
            $this->fail('Second in-flight fill must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::InFlightExists, $exception->errorCode);
        }

        $this->assertSame(0, $fake->calls);
        $this->assertSame(1, QuestionBlueprint::query()->count());
        Queue::assertPushed(FillQuestionBlueprintJob::class, 1);
    }

    public function test_throttle_resets_after_one_hour(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();
        $queue = $this->app->make(QueueBlueprintAiFill::class);

        foreach (range(1, 3) as $ignored) {
            $created = $queue->handle($user, $material);
            $created->update([
                'ai_fill_status' => BlueprintAiFillStatus::Failed,
                'workflow_token' => null,
                'step_execution_token' => null,
            ]);
        }

        $this->travel(3601)->seconds();

        $fourth = $queue->handle($user, $material);

        $this->assertSame(BlueprintAiFillStatus::Queued, $fourth->ai_fill_status);
        $this->assertSame(4, QuestionBlueprint::query()->count());
    }

    public function test_invalid_candidate_rejects_the_entire_response(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = function () use ($fake) {
            $request = $fake->requests[0] ?? null;
            $this->assertNotNull($request);

            return new BlueprintFillResult(
                [
                    new BlueprintFillCandidate(
                        'Tujuan',
                        'Topik',
                        'Indikator',
                        'understand',
                        'medium',
                        99,
                        [],
                    ),
                ],
                new BlueprintProviderAttemptMetadata(
                    $fake::PROVIDER_NAME,
                    'fake-model',
                    'blueprint-fill-v1',
                    1,
                    1,
                    2,
                    3,
                ),
            );
        };

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)
            ->handle($user, $material);

        $this->drainBlueprintJobs();

        $blueprint->refresh();
        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(0, $blueprint->rows()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_stale_recovery_fails_processing_fill_without_provider_call(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)
            ->handle($user, $material);

        $blueprint->update([
            'ai_fill_status' => BlueprintAiFillStatus::Processing,
            'lease_expires_at' => now()->subMinute(),
        ]);

        $recovered = $this->app->make(RecoverStaleBlueprintFills::class)->handle();

        $this->assertSame(1, $recovered);
        $blueprint->refresh();
        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertNull($blueprint->workflow_token);
        $this->assertSame(0, $fake->calls);
        $this->assertSame(0, AiUsageLog::query()->count());
    }
}
