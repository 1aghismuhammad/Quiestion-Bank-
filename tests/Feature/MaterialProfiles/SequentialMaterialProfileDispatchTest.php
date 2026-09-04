<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\ClaimMaterialProfileStep;
use App\Actions\MaterialProfiles\DispatchNextMaterialProfileStep;
use App\Actions\MaterialProfiles\RunMaterialProfileMapStep;
use App\Actions\MaterialProfiles\RunMaterialProfileReduceStep;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderTransientException;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class SequentialMaterialProfileDispatchTest extends TestCase
{
    use RefreshDatabase;
    use RunsMaterialProfileWorkflows;

    private FakeMaterialProfileAnalysisProvider $provider;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->provider = $this->fakeProfileProvider();
        $this->user = User::factory()->create();
    }

    public function test_map_steps_execute_in_ascending_order_and_reduce_runs_last(): void
    {
        $material = $this->multiChunkMaterial(3);

        $version = $this->completeProfileAnalysis($this->user, $material);

        $this->assertSame(MaterialProfileStatus::READY, $version->status);
        $this->assertSame(3, $this->provider->mapCalls);
        $this->assertSame(1, $this->provider->reduceCalls);
        $this->assertSame(
            [0, 1, 2],
            array_map(static fn ($request): int => $request->chunkIndex, $this->provider->mapRequests),
        );

        Queue::assertPushed(AnalyzeMaterialProfileMapJob::class, 3);
        Queue::assertPushed(ReduceMaterialProfileJob::class, 1);

        $steps = $this->stepsOf($version);
        foreach ($steps as $step) {
            $this->assertSame(MaterialProfileStepStatus::READY, $step->status);
            $this->assertNull($step->lease_expires_at);
        }
    }

    public function test_only_the_expected_step_can_be_claimed(): void
    {
        $material = $this->multiChunkMaterial(3);
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $claim = $this->app->make(ClaimMaterialProfileStep::class);

        $second = $this->mapStep($version, 1);
        $reduce = $this->reduceStepOf($version);

        foreach ([$second, $reduce] as $step) {
            $outcome = $claim->handle(
                (int) $version->profile_version_id,
                (int) $step->profile_step_id,
                (string) $version->workflow_token,
                (string) Str::uuid(),
            );

            $this->assertSame(MaterialProfileClaimOutcome::NotNextStep, $outcome->outcome);
            $this->assertFalse($outcome->shouldRun);
            $this->assertSame(MaterialProfileStepStatus::QUEUED, $step->fresh()->status);
            $this->assertNull($step->fresh()->step_execution_token);
        }
    }

    public function test_only_one_step_is_processing_at_a_time(): void
    {
        $material = $this->multiChunkMaterial(3);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function ($request) use ($version) {
            $processing = MaterialProfileStep::query()
                ->where('profile_version_id', $version->profile_version_id)
                ->where('status', MaterialProfileStepStatus::PROCESSING)
                ->get();

            $this->assertCount(1, $processing, 'Exactly one Step may hold processing authority.');

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $this->drainProfileJobs();

        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
    }

    public function test_reduce_calls_no_provider_until_every_map_is_ready(): void
    {
        $material = $this->multiChunkMaterial(3);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        // Force-dispatch the reduce Step out of order and deliver it early.
        $reduce = $this->reduceStepOf($version);
        $reduce->step_execution_token = (string) Str::uuid();
        $reduce->step_queued_at = now();
        $reduce->save();

        (new ReduceMaterialProfileJob(
            (int) $version->profile_version_id,
            (int) $reduce->profile_step_id,
            (string) $version->workflow_token,
            (string) $reduce->step_execution_token,
        ))->handle($this->app->make(RunMaterialProfileReduceStep::class));

        $this->assertSame(0, $this->provider->reduceCalls);
        $this->assertSame(0, MaterialProfileAttempt::query()->count());
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $reduce->fresh()->status);
        $this->assertSame(MaterialProfileStatus::QUEUED, $version->fresh()->status);
    }

    public function test_every_step_receives_a_distinct_execution_token_under_one_workflow_token(): void
    {
        $material = $this->multiChunkMaterial(3);
        $version = $this->completeProfileAnalysis($this->user, $material);

        $steps = $this->stepsOf($version);
        $tokens = $steps->pluck('step_execution_token')->all();
        $workflowTokens = $steps->pluck('workflow_token')->unique()->all();

        $this->assertCount(4, $steps);
        $this->assertCount(4, array_unique($tokens));
        $this->assertNotContains(null, $tokens);
        $this->assertSame([(string) $version->workflow_token], $workflowTokens);

        // The Version workflow token was never re-minted along the way.
        $jobTokens = array_map(
            static fn ($job): string => $job->workflowToken,
            [...$this->pushedMapJobs(), ...$this->pushedReduceJobs()],
        );
        $this->assertSame([(string) $version->workflow_token], array_values(array_unique($jobTokens)));
    }

    public function test_retry_retains_the_same_step_execution_token(): void
    {
        $material = $this->singleChunkMaterial();
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $this->provider->mapUsing = function ($request, int $call) {
            return $call === 1
                ? new MaterialProfileProviderTransientException
                : FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $job = $this->pushedMapJobs()[0];
        $tokenBefore = $job->stepExecutionToken;

        $exception = $this->runProfileJobExpectingRetry($job);
        $this->assertInstanceOf(MaterialProfileProviderTransientException::class, $exception);

        $step = $this->mapStep($version, 0)->fresh();
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->status);
        $this->assertSame($tokenBefore, (string) $step->step_execution_token);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(1, MaterialProfileAttempt::query()->count());
        $this->assertSame(0, MaterialProfileElement::query()->count());

        // The queue redelivers the identical serialized Job, token included.
        $this->runProfileJob($job);

        $step = $this->mapStep($version, 0)->fresh();
        $this->assertSame(MaterialProfileStepStatus::READY, $step->status);
        $this->assertSame($tokenBefore, (string) $step->step_execution_token);
        $this->assertSame(2, MaterialProfileAttempt::query()
            ->where('profile_step_id', $step->profile_step_id)
            ->count());
        $this->assertSame([1, 2], MaterialProfileAttempt::query()
            ->where('profile_step_id', $step->profile_step_id)
            ->orderBy('attempt_number')
            ->pluck('attempt_number')
            ->map(static fn ($number): int => (int) $number)
            ->all());
    }

    public function test_repeated_dispatch_does_not_create_a_competing_token(): void
    {
        $material = $this->multiChunkMaterial(2);
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $dispatcher = $this->app->make(DispatchNextMaterialProfileStep::class);

        $first = $this->mapStep($version, 0);
        $tokenBefore = (string) $first->step_execution_token;
        $queuedAtBefore = $first->step_queued_at?->toIso8601String();

        $again = $dispatcher->handle((int) $version->profile_version_id);
        $andAgain = $dispatcher->handle((int) $version->profile_version_id);

        $this->assertNotNull($again);
        $this->assertSame($tokenBefore, $again->stepExecutionToken);
        $this->assertSame($tokenBefore, $andAgain?->stepExecutionToken);

        $first = $first->fresh();
        $this->assertSame($tokenBefore, (string) $first->step_execution_token);
        $this->assertSame($queuedAtBefore, $first->step_queued_at?->toIso8601String());

        // Redelivery is safe: the workflow still completes exactly once.
        $this->drainProfileJobs();
        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
        $this->assertSame(2, $this->provider->mapCalls);
        $this->assertSame(1, $this->provider->reduceCalls);
    }

    public function test_queued_step_with_a_different_stored_token_is_not_overwritten(): void
    {
        $material = $this->singleChunkMaterial();
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $step = $this->mapStep($version, 0);
        $storedToken = (string) $step->step_execution_token;

        $outcome = $this->app->make(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            (string) Str::uuid(),
        );

        $this->assertSame(MaterialProfileClaimOutcome::Duplicate, $outcome->outcome);
        $this->assertFalse($outcome->shouldRun);
        $this->assertSame($storedToken, (string) $step->fresh()->step_execution_token);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $step->fresh()->status);
    }

    public function test_duplicate_worker_with_a_foreign_token_calls_the_provider_zero_times(): void
    {
        $material = $this->singleChunkMaterial();
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $step = $this->mapStep($version, 0);

        (new AnalyzeMaterialProfileMapJob(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            (string) Str::uuid(),
        ))->handle($this->app->make(RunMaterialProfileMapStep::class));

        $this->assertSame(0, $this->provider->mapCalls);
        $this->assertSame(0, MaterialProfileAttempt::query()->count());
    }

    public function test_terminal_duplicate_delivery_calls_the_provider_zero_times(): void
    {
        $material = $this->multiChunkMaterial(2);
        $version = $this->completeProfileAnalysis($this->user, $material);
        $this->assertSame(MaterialProfileStatus::READY, $version->status);

        $mapCalls = $this->provider->mapCalls;
        $reduceCalls = $this->provider->reduceCalls;
        $attempts = MaterialProfileAttempt::query()->count();
        $elements = MaterialProfileElement::query()->count();
        Queue::fake();

        // Replay every delivery the queue could still hold.
        foreach ([...$this->pushedMapJobs(), ...$this->pushedReduceJobs()] as $job) {
            $this->runProfileJob($job);
        }

        $this->assertSame($mapCalls, $this->provider->mapCalls);
        $this->assertSame($reduceCalls, $this->provider->reduceCalls);
        $this->assertSame($attempts, MaterialProfileAttempt::query()->count());
        $this->assertSame($elements, MaterialProfileElement::query()->count());
        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_completed_map_step_redelivery_creates_no_second_attempt_or_element(): void
    {
        $material = $this->multiChunkMaterial(2);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $job = $this->pushedMapJobs()[0];
        $this->runProfileJob($job);

        $attempts = MaterialProfileAttempt::query()->count();
        $elements = MaterialProfileElement::query()->count();
        $mapJobs = count($this->pushedMapJobs());

        $this->runProfileJob($job);

        $this->assertSame(1, $this->provider->mapCalls);
        $this->assertSame($attempts, MaterialProfileAttempt::query()->count());
        $this->assertSame($elements, MaterialProfileElement::query()->count());
        $this->assertCount($mapJobs, $this->pushedMapJobs());
    }

    private function singleChunkMaterial(): Material
    {
        return Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar satu bagian tentang ekosistem dan rantai makanan.',
        ]);
    }

    private function multiChunkMaterial(int $chunks): Material
    {
        return Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent($chunks),
        ]);
    }

    private function stepsOf(MaterialProfileVersion $version)
    {
        return MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->orderBy('step_index')
            ->get();
    }
}
