<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\RecoverStaleMaterialProfiles;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileRecoveryRaceTest extends TestCase
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

    public function test_recovery_winning_during_the_provider_call_discards_the_late_map_result(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function ($request) {
            // The lease lapses while the provider is working and recovery wins.
            $this->travel(121)->seconds();
            $this->assertSame(1, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $job = $this->pushedMapJobs()[0];
        $this->runProfileJob($job);

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::StaleRecovery->value, (string) $version->error_code);
        $this->assertSame(1, $this->provider->mapCalls);
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(
            MaterialProfileAttemptStatus::STARTED,
            MaterialProfileAttempt::query()->firstOrFail()->status,
            'A late worker cannot complete its Attempt row.',
        );
        $this->assertSame(MaterialProfileStepStatus::FAILED, $this->mapStep($version, 0)->fresh()->status);
        $this->assertCount(1, $this->pushedMapJobs(), 'No next Step was dispatched.');
        $this->assertSame([], $this->pushedReduceJobs());
    }

    public function test_recovery_winning_during_reduce_discards_the_late_result(): void
    {
        $material = $this->material('Materi ajar tentang perubahan iklim dan dampaknya.');
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->reduceUsing = function () {
            $this->travel(121)->seconds();
            $this->assertSame(1, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());

            return FakeMaterialProfileAnalysisProvider::defaultReduceResult();
        };

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->runProfileJob($this->pushedReduceJobs()[0]);

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertNull($version->completed_at);
        $this->assertSame(1, $this->provider->reduceCalls);
        $this->assertSame(0, MaterialProfileElement::query()
            ->where('origin', 'suggested')
            ->count());
        $this->assertSame(MaterialProfileStepStatus::FAILED, $this->reduceStepOf($version)->fresh()->status);
    }

    public function test_an_expired_lease_blocks_persistence_even_before_recovery_runs(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function ($request) {
            $this->travel(121)->seconds();

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $this->runProfileJob($this->pushedMapJobs()[0]);

        $step = $this->mapStep($version, 0)->fresh();
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->status, 'Recovery has not run yet.');
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(
            MaterialProfileAttemptStatus::STARTED,
            MaterialProfileAttempt::query()->firstOrFail()->status,
        );
        $this->assertCount(1, $this->pushedMapJobs());
    }

    public function test_worker_winning_first_survives_a_later_recovery_sweep(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->completeProfileAnalysis($this->user, $material);
        $readyAt = $version->completed_at->toIso8601String();

        $this->travel(2)->hours();
        $this->assertSame(0, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::READY, $version->status);
        $this->assertSame($readyAt, $version->completed_at->toIso8601String());
        $this->assertNull($version->failed_at);
        $this->assertSame(0, MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('status', '!=', MaterialProfileStepStatus::READY->value)
            ->count());
    }

    public function test_recovery_leaves_a_fresh_workflow_alone(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->assertSame(0, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());
        $this->assertSame(MaterialProfileStatus::QUEUED, $version->fresh()->status);

        // Mid-flight with a live lease is equally untouchable.
        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->assertSame(0, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(MaterialProfileStepStatus::READY, $this->mapStep($version, 0)->fresh()->status);
    }

    public function test_queued_and_processing_clocks_remain_distinct(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        // 121 seconds is past the processing lease but far short of the queued
        // abandonment window, and nothing is processing yet.
        $this->travel(121)->seconds();
        $this->assertSame(0, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());
        $this->assertSame(MaterialProfileStatus::QUEUED, $version->fresh()->status);

        $this->travel(900)->seconds();
        $this->assertSame(1, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::QueuedAbandoned->value, (string) $version->error_code);
        $this->assertSame(0, $this->provider->mapCalls);
    }

    public function test_an_obsolete_failed_handler_cannot_fail_a_newer_workflow(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $first = $this->startProfileAnalysis($this->user, $material)->version;
        $obsoleteJob = $this->pushedMapJobs()[0];

        // The first workflow dies through recovery, then the owner regenerates.
        $this->travel(1_000)->seconds();
        $this->app->make(RecoverStaleMaterialProfiles::class)->handle();
        $firstFailedAt = $first->fresh()->failed_at->toIso8601String();

        $second = $this->startProfileAnalysis($this->user, $material->fresh(), forceRegenerate: true)->version;

        $obsoleteJob->failed(new RuntimeException('worker died'));

        $this->assertSame($firstFailedAt, $first->fresh()->failed_at->toIso8601String());
        $this->assertSame(MaterialProfileErrorCode::QueuedAbandoned->value, (string) $first->fresh()->error_code);

        $second = $second->fresh();
        $this->assertSame(MaterialProfileStatus::QUEUED, $second->status);
        $this->assertNull($second->failed_at);
        $this->assertNull($second->error_code);

        // The regenerated workflow still runs to a healthy ready state.
        $this->drainProfileJobs();
        $this->assertSame(MaterialProfileStatus::READY, $second->fresh()->status);
    }

    public function test_an_obsolete_failed_handler_cannot_fail_a_ready_workflow(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->completeProfileAnalysis($this->user, $material);
        $completedAt = $version->completed_at->toIso8601String();

        foreach ([...$this->pushedMapJobs(), ...$this->pushedReduceJobs()] as $job) {
            $job->failed(new RuntimeException('late worker crash'));
        }

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::READY, $version->status);
        $this->assertSame($completedAt, $version->completed_at->toIso8601String());
        $this->assertNull($version->failed_at);
        $this->assertNull($version->error_code);
    }

    public function test_queued_pre_claim_map_failed_handler_terminal_fails_the_workflow(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $job = $this->pushedMapJobs()[0];

        $job->failed(new RuntimeException('worker exhausted its retries'));

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertSame(0, $this->provider->mapCalls);
        $this->assertSame(0, MaterialProfileElement::query()->count());
    }

    public function test_queued_pre_claim_reduce_failed_handler_is_a_noop_while_the_version_is_processing(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->runProfileJob($this->pushedMapJobs()[1]);

        $reduceJob = $this->pushedReduceJobs()[0];
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $this->reduceStepOf($version)->fresh()->status);
        $this->assertNull($this->reduceStepOf($version)->fresh()->claimed_at);

        $reduceJob->failed(new RuntimeException('reduce died before claim'));

        $version = $version->fresh();
        $reduce = $this->reduceStepOf($version)->fresh();
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->status);
        $this->assertNull($version->error_code);
        $this->assertNull($version->failed_at);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $reduce->status);
        $this->assertNull($reduce->error_code);
    }

    public function test_expired_map_failed_handler_writes_nothing_and_recovery_assigns_stale_recovery(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $job = $this->pushedMapJobs()[0];

        $this->provider->mapUsing = function ($request) {
            $this->travel(121)->seconds();

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $this->runProfileJob($job);

        $step = $this->mapStep($version, 0)->fresh();
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->status);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertNull($version->fresh()->error_code);
        $this->assertNull($step->error_code);

        $job->failed(new RuntimeException('failed after the lease expired'));

        $version = $version->fresh();
        $step = $step->fresh();
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->status);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->status);
        $this->assertNull($version->error_code);
        $this->assertNull($step->error_code);
        $this->assertSame(0, MaterialProfileElement::query()->count());

        $this->assertSame(1, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());

        $version = $version->fresh();
        $step = $step->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::StaleRecovery->value, (string) $version->error_code);
        $this->assertSame(MaterialProfileStepStatus::FAILED, $step->status);
        $this->assertSame(MaterialProfileErrorCode::StaleRecovery->value, (string) $step->error_code);
    }

    public function test_expired_reduce_failed_handler_writes_nothing_and_recovery_assigns_stale_recovery(): void
    {
        $material = $this->material('Materi ajar tentang gaya gravitasi bumi dan penerapannya.');
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $job = $this->pushedReduceJobs()[0];

        $this->provider->reduceUsing = function () {
            $this->travel(121)->seconds();

            return FakeMaterialProfileAnalysisProvider::defaultReduceResult();
        };

        $this->runProfileJob($job);

        $step = $this->reduceStepOf($version)->fresh();
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->status);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertNull($version->fresh()->error_code);

        $job->failed(new RuntimeException('reduce failed after the lease expired'));

        $version = $version->fresh();
        $step = $step->fresh();
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->status);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->status);
        $this->assertNull($version->error_code);
        $this->assertNull($step->error_code);
        $this->assertSame(0, MaterialProfileElement::query()
            ->where('origin', 'suggested')
            ->count());

        $this->assertSame(1, $this->app->make(RecoverStaleMaterialProfiles::class)->handle());

        $version = $version->fresh();
        $step = $step->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::StaleRecovery->value, (string) $version->error_code);
        $this->assertSame(MaterialProfileStepStatus::FAILED, $step->status);
        $this->assertSame(MaterialProfileErrorCode::StaleRecovery->value, (string) $step->error_code);
    }

    public function test_live_processing_map_failed_handler_terminal_fails_exactly_once(): void
    {
        $material = $this->material($this->multiChunkContent(2));
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $job = $this->pushedMapJobs()[0];

        $this->provider->mapUsing = function ($request) use ($job) {
            $job->failed(new RuntimeException('worker died while processing'));

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $this->runProfileJob($job);

        $version = $version->fresh();
        $failedAt = $version->failed_at?->toIso8601String();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertNotNull($failedAt);
        $this->assertSame(0, MaterialProfileElement::query()->count());

        $job->failed(new RuntimeException('second failed callback'));

        $this->assertSame($failedAt, $version->fresh()->failed_at?->toIso8601String());
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->fresh()->error_code);
    }

    public function test_live_processing_reduce_failed_handler_terminal_fails_exactly_once(): void
    {
        $material = $this->material('Materi ajar tentang gaya gravitasi bumi dan penerapannya.');
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $job = $this->pushedReduceJobs()[0];

        $this->provider->reduceUsing = function () use ($job) {
            $job->failed(new RuntimeException('reduce worker died while processing'));

            return FakeMaterialProfileAnalysisProvider::defaultReduceResult();
        };

        $this->runProfileJob($job);

        $version = $version->fresh();
        $failedAt = $version->failed_at?->toIso8601String();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertNotNull($failedAt);
        $this->assertSame(0, MaterialProfileElement::query()
            ->where('origin', 'suggested')
            ->count());

        $job->failed(new RuntimeException('second reduce failed callback'));

        $this->assertSame($failedAt, $version->fresh()->failed_at?->toIso8601String());
    }

    public function test_a_failed_version_can_never_become_ready(): void
    {
        $material = $this->material('Materi ajar tentang gaya gravitasi bumi.');
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $mapJob = $this->pushedMapJobs()[0];

        $this->runProfileJob($mapJob);
        $reduceJob = $this->pushedReduceJobs()[0];

        // The workflow dies between reduce dispatch and reduce execution.
        $this->travel(1_000)->seconds();
        $this->app->make(RecoverStaleMaterialProfiles::class)->handle();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);

        $this->runProfileJob($reduceJob);
        $this->runProfileJob($mapJob);

        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(0, $this->provider->reduceCalls);
        $this->assertSame(1, $this->provider->mapCalls);
    }

    public function test_terminal_workflow_dispatches_nothing_further(): void
    {
        $material = $this->material($this->multiChunkContent(3));
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->travel(1_000)->seconds();
        $this->app->make(RecoverStaleMaterialProfiles::class)->handle();

        Queue::fake();
        foreach (MaterialProfileStep::query()->where('profile_version_id', $version->profile_version_id)->get() as $step) {
            $this->runProfileJob(new AnalyzeMaterialProfileMapJob(
                (int) $version->profile_version_id,
                (int) $step->profile_step_id,
                (string) $version->workflow_token,
                (string) ($step->step_execution_token ?? 'no-token'),
            ));
        }

        $this->runProfileJob(new ReduceMaterialProfileJob(
            (int) $version->profile_version_id,
            (int) $this->reduceStepOf($version)->profile_step_id,
            (string) $version->workflow_token,
            'no-token',
        ));

        Queue::assertNothingPushed();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
    }

    private function material(string $content): Material
    {
        return Material::factory()->text()->for($this->user)->create(['content' => $content]);
    }
}
