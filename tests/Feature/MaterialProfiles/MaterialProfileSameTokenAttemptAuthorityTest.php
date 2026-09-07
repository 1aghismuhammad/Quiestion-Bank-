<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\BeginMaterialProfileAttempt;
use App\Actions\MaterialProfiles\ClaimMaterialProfileStep;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderTransientException;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileSameTokenAttemptAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use RunsMaterialProfileWorkflows;

    private FakeMaterialProfileAnalysisProvider $provider;

    private User $user;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->provider = $this->fakeProfileProvider();
        $this->user = User::factory()->create();
        $this->material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar satu bagian tentang ekosistem dan rantai makanan.',
        ]);
    }

    public function test_same_token_redelivery_is_rejected_while_an_attempt_is_started(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];
        $step = $this->mapStep($version, 0);

        $this->assertSame(
            MaterialProfileClaimOutcome::Claimed,
            app(ClaimMaterialProfileStep::class)->handle(
                (int) $version->profile_version_id,
                (int) $step->profile_step_id,
                (string) $version->workflow_token,
                $job->stepExecutionToken,
            )->outcome,
        );

        $attempt = app(BeginMaterialProfileAttempt::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $job->stepExecutionToken,
            FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
            (string) config('material_profile.primary_model'),
            (string) config('material_profile.map_prompt_version'),
        );

        $this->assertNotNull($attempt);
        $this->assertSame(MaterialProfileAttemptStatus::STARTED, $attempt->status);
        $this->assertSame(1, (int) $attempt->attempt_number);

        $step = $step->fresh();
        $lease = $step->lease_expires_at?->toIso8601String();
        $heartbeat = $step->heartbeat_at?->toIso8601String();
        $mapCalls = $this->provider->mapCalls;

        Carbon::setTestNow(now()->addSeconds(5));

        $claim = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $job->stepExecutionToken,
        );

        $this->assertSame(MaterialProfileClaimOutcome::Duplicate, $claim->outcome);
        $this->assertFalse($claim->shouldRun);
        $this->assertSame($lease, $step->fresh()->lease_expires_at?->toIso8601String());
        $this->assertSame($heartbeat, $step->fresh()->heartbeat_at?->toIso8601String());
        $this->assertSame(1, MaterialProfileAttempt::query()->count());
        $this->assertSame($mapCalls, $this->provider->mapCalls);

        Carbon::setTestNow();
    }

    public function test_begin_rejects_a_second_started_attempt_even_when_claim_is_bypassed(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];
        $step = $this->mapStep($version, 0);

        app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $job->stepExecutionToken,
        );

        $begin = app(BeginMaterialProfileAttempt::class);
        $first = $begin->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $job->stepExecutionToken,
            FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
            (string) config('material_profile.primary_model'),
            (string) config('material_profile.map_prompt_version'),
        );
        $step = $step->fresh();
        $lease = $step->lease_expires_at?->toIso8601String();

        Carbon::setTestNow(now()->addSeconds(5));

        $second = $begin->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $job->stepExecutionToken,
            FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
            (string) config('material_profile.primary_model'),
            (string) config('material_profile.map_prompt_version'),
        );

        Carbon::setTestNow();

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, MaterialProfileAttempt::query()->count());
        $this->assertSame(MaterialProfileAttemptStatus::STARTED, $first->fresh()->status);
        $this->assertSame($lease, $step->fresh()->lease_expires_at?->toIso8601String());
        $this->assertSame(0, $this->provider->mapCalls);
    }

    public function test_failed_attempt_does_not_block_retry_with_the_same_step_token(): void
    {
        $this->provider->mapUsing = function ($request, int $call) {
            if ($call === 1) {
                throw new MaterialProfileProviderTransientException(
                    message: 'temporary map failure',
                );
            }

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];
        $token = $job->stepExecutionToken;

        $this->assertNotNull($this->runProfileJobExpectingRetry($job));
        $this->assertSame(1, MaterialProfileAttempt::query()->count());
        $this->assertSame(MaterialProfileAttemptStatus::FAILED, MaterialProfileAttempt::query()->firstOrFail()->status);
        $this->assertSame($token, (string) $this->mapStep($version, 0)->fresh()->step_execution_token);

        $this->runProfileJob($job);
        $this->drainProfileJobs();

        $attempts = MaterialProfileAttempt::query()->orderBy('profile_attempt_id')->get();
        $this->assertCount(3, $attempts);
        $this->assertSame([1, 2, 1], $attempts->pluck('attempt_number')->map(static fn ($n): int => (int) $n)->all());
        $this->assertSame(
            [MaterialProfileAttemptStatus::FAILED, MaterialProfileAttemptStatus::SUCCEEDED, MaterialProfileAttemptStatus::SUCCEEDED],
            $attempts->pluck('status')->all(),
        );
        $this->assertSame($token, (string) $this->mapStep($version, 0)->fresh()->step_execution_token);
        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
        $this->assertSame(2, $this->provider->mapCalls);
    }

    public function test_ready_failed_expired_revoked_and_foreign_token_deliveries_remain_no_ops(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];
        $this->drainProfileJobs();
        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);

        $mapCalls = $this->provider->mapCalls;
        $attempts = MaterialProfileAttempt::query()->count();
        $this->runProfileJob($job);
        $this->assertSame($mapCalls, $this->provider->mapCalls);
        $this->assertSame($attempts, MaterialProfileAttempt::query()->count());

        $queued = $this->startProfileAnalysis($this->user, Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi antri untuk no-op token.',
        ]))->version;
        $queuedJob = collect($this->pushedMapJobs())->last();
        $this->assertInstanceOf(AnalyzeMaterialProfileMapJob::class, $queuedJob);

        $revoked = app(ClaimMaterialProfileStep::class)->handle(
            (int) $queued->profile_version_id,
            $queuedJob->profileStepId,
            '00000000-0000-0000-0000-000000000000',
            $queuedJob->stepExecutionToken,
        );
        $this->assertSame(MaterialProfileClaimOutcome::Revoked, $revoked->outcome);
        $this->assertFalse($revoked->shouldRun);

        $foreign = app(ClaimMaterialProfileStep::class)->handle(
            (int) $queued->profile_version_id,
            $queuedJob->profileStepId,
            (string) $queued->workflow_token,
            '11111111-1111-1111-1111-111111111111',
        );
        $this->assertSame(MaterialProfileClaimOutcome::Duplicate, $foreign->outcome);
        $this->assertFalse($foreign->shouldRun);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $this->mapStep($queued, 0)->fresh()->status);

        $this->assertSame(
            MaterialProfileClaimOutcome::Claimed,
            app(ClaimMaterialProfileStep::class)->handle(
                (int) $queued->profile_version_id,
                $queuedJob->profileStepId,
                (string) $queued->workflow_token,
                $queuedJob->stepExecutionToken,
            )->outcome,
        );

        Carbon::setTestNow(now()->addSeconds(121));
        $expired = app(ClaimMaterialProfileStep::class)->handle(
            (int) $queued->profile_version_id,
            $queuedJob->profileStepId,
            (string) $queued->workflow_token,
            $queuedJob->stepExecutionToken,
        );
        Carbon::setTestNow();

        $this->assertSame(MaterialProfileClaimOutcome::Expired, $expired->outcome);
        $this->assertFalse($expired->shouldRun);
        $this->assertSame(0, $this->provider->mapCalls - $mapCalls);
    }
}
