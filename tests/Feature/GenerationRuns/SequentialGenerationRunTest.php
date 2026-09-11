<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\ClaimRunChildExecution;
use App\Actions\GenerationRuns\RecoverStaleGenerationRuns;
use App\Actions\GenerationRuns\RetryFailedGenerationRun;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\BeginGenerationAttempt;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Enums\UsageStatus;
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
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class SequentialGenerationRunTest extends TestCase
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

    public function test_children_run_sequentially_and_charge_once(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            $this->sampleRow(2, DifficultyLevel::MEDIUM),
            array_merge($this->sampleRow(3, DifficultyLevel::MEDIUM), [
                'topic' => 'Penerapan',
                'indicator' => 'Peserta memberi tiga contoh.',
            ]),
        ]));

        Http::fake(function () {
            static $batch = 0;
            $batch++;

            $questions = $batch === 1
                ? GeminiFakeResponses::questions(2, 'First')
                : GeminiFakeResponses::questions(3, 'Second');

            return Http::response(GeminiFakeResponses::success($questions));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->assertSame(GenerationRunStatus::Queued, $run->status);
        $this->assertSame(2, $run->children()->count());
        Queue::assertPushed(GenerateQuestionsJob::class, 1);

        $started = $run->children()->orderBy('child_index')->get();
        $this->assertNotNull($started[0]->queued_at);
        $this->assertNotEmpty((string) $started[0]->execution_token);
        $this->assertNull($started[1]->queued_at);
        $this->assertNull($started[1]->execution_token);

        $firstJob = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->assertInstanceOf(GenerateQuestionsJob::class, $firstJob);
        $firstJob->handle($this->app->make(RunQuestionGeneration::class));

        $children = AiGeneration::query()->where('generation_run_id', $run->generation_run_id)->orderBy('child_index')->get();
        $this->assertSame(GenerationStatus::COMPLETED, $children[0]->generation_status);
        $this->assertSame(GenerationStatus::QUEUED, $children[1]->generation_status);
        $this->assertSame(GenerationRunStatus::Processing, $run->fresh()->status);
        $this->assertSame(UsageStatus::RESERVED, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->first()?->status);
        Queue::assertPushed(GenerateQuestionsJob::class, 2);

        $secondJob = Queue::pushed(GenerateQuestionsJob::class)->last();
        $secondJob->handle($this->app->make(RunQuestionGeneration::class));

        $run->refresh();
        $this->assertSame(GenerationRunStatus::Completed, $run->status);
        $this->assertSame(UsageStatus::CHARGED, $run->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertTrue(
            AiGeneration::query()
                ->where('generation_run_id', $run->generation_run_id)
                ->get()
                ->every(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::COMPLETED),
        );
    }

    public function test_child_failure_aborts_later_children_and_releases_once(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            $this->sampleRow(2),
            array_merge($this->sampleRow(2), ['topic' => 'Penerapan']),
        ]));

        Http::fake(fn () => Http::response(['error' => ['message' => 'boom']], 500));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $run->refresh();
        $this->assertSame(GenerationRunStatus::Failed, $run->status);
        $this->assertSame(UsageStatus::RELEASED, $run->usageLog->status);

        $statuses = AiGeneration::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->orderBy('child_index')
            ->pluck('generation_status')
            ->map(fn ($status) => $status->value)
            ->all();

        $this->assertSame(['failed', 'failed'], $statuses);
        $this->assertSame(1, Queue::pushed(GenerateQuestionsJob::class)->count());
    }

    public function test_same_token_duplicate_does_not_start_a_second_attempt(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->assertInstanceOf(GenerateQuestionsJob::class, $job);

        $claim = $this->app->make(ClaimRunChildExecution::class);
        $this->assertTrue($claim->handle($job->generationId, $job->executionToken)->shouldRun);

        $this->app->make(BeginGenerationAttempt::class)->handle(
            $job->generationId,
            $job->executionToken,
            GenerationAttemptPurpose::INITIAL,
            5,
            'fake-model',
            'mcq-v1',
        );

        $duplicate = $claim->handle($job->generationId, $job->executionToken);

        $this->assertFalse($duplicate->shouldRun);
        $this->assertSame(1, AiGenerationAttempt::query()->count());
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }

    public function test_stale_recovery_fails_the_run_and_releases_once(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $run->update(['queued_at' => now()->subHours(2)]);
        AiGeneration::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->update(['queued_at' => now()->subHours(2)]);

        $recovered = $this->app->make(RecoverStaleGenerationRuns::class)->handle();

        $this->assertSame(1, $recovered);
        $run->refresh();
        $this->assertSame(GenerationRunStatus::Failed, $run->status);
        $this->assertSame(UsageStatus::RELEASED, $run->usageLog->status);
        $this->assertTrue(
            AiGeneration::query()
                ->where('generation_run_id', $run->generation_run_id)
                ->get()
                ->every(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::FAILED),
        );
    }

    public function test_retry_failed_run_creates_a_new_reservation(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));

        Http::fake(fn () => Http::response(['error' => ['message' => 'boom']], 500));

        $failed = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $failed->refresh();
        $this->assertSame(GenerationRunStatus::Failed, $failed->status);
        $this->assertSame(UsageStatus::RELEASED, $failed->usageLog->status);

        $retried = $this->app->make(RetryFailedGenerationRun::class)->handle(
            $user,
            $failed,
            (string) Str::uuid(),
        );

        $this->assertNotSame($failed->generation_run_id, $retried->generation_run_id);
        $this->assertSame($failed->generation_run_id, $retried->parent_run_id);
        $this->assertSame(GenerationRunStatus::Failed, $failed->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $failed->fresh()->usageLog->status);
        $this->assertSame(UsageStatus::RESERVED, $retried->usageLog->status);
        $this->assertSame(2, AiUsageLog::query()->whereNotNull('generation_run_id')->count());
        Queue::assertPushed(GenerateQuestionsJob::class, 2);
    }
}
