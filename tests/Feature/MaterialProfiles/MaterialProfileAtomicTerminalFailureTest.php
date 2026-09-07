<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\BeginMaterialProfileAttempt;
use App\Actions\MaterialProfiles\ClaimMaterialProfileStep;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderPermanentException;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderTransientException;
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

class MaterialProfileAtomicTerminalFailureTest extends TestCase
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

    public function test_permanent_provider_failure_atomically_fails_attempt_step_and_version(): void
    {
        $this->provider->mapUsing = static fn () => throw new MaterialProfileProviderPermanentException;

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];
        $this->assertNull($this->runProfileJobExpectingRetry($job));

        $this->assertAtomicTerminalFailure($version, $job->stepExecutionToken, MaterialProfileAttemptErrorCode::ProviderHttp);
        $this->assertSame(1, $this->provider->mapCalls);

        $this->runProfileJob($job);
        $this->assertSame(1, $this->provider->mapCalls);
        $this->assertSame(1, MaterialProfileAttempt::query()->count());
        Queue::assertNotPushed(ReduceMaterialProfileJob::class);
    }

    public function test_unexpected_runtime_exception_uses_sanitized_provider_http_and_the_atomic_path(): void
    {
        $this->provider->mapUsing = static fn () => throw new RuntimeException('SECRET_PROVIDER_THROWABLE_do-not-persist');

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];
        $this->assertNull($this->runProfileJobExpectingRetry($job));

        $this->assertAtomicTerminalFailure($version, $job->stepExecutionToken, MaterialProfileAttemptErrorCode::ProviderHttp);
        $this->assertStringNotContainsString(
            'SECRET_PROVIDER_THROWABLE_do-not-persist',
            (string) json_encode(MaterialProfileAttempt::query()->firstOrFail()->getAttributes()),
        );
    }

    public function test_maximum_attempt_exhaustion_uses_the_atomic_terminal_path(): void
    {
        $this->provider->mapUsing = static fn () => throw new MaterialProfileProviderTransientException;

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];

        $this->assertNotNull($this->runProfileJobExpectingRetry($job));
        $this->assertNotNull($this->runProfileJobExpectingRetry($job));
        $this->assertNull($this->runProfileJobExpectingRetry($job));

        $this->assertAtomicTerminalFailure($version, $job->stepExecutionToken, MaterialProfileAttemptErrorCode::ProviderHttp);
        $this->assertSame(3, MaterialProfileAttempt::query()->count());
        $this->assertSame(3, $this->provider->mapCalls);

        $this->runProfileJob($job);
        $this->assertSame(3, $this->provider->mapCalls);
        $this->assertSame(3, MaterialProfileAttempt::query()->count());
    }

    public function test_lost_or_expired_authority_writes_nothing_on_permanent_failure(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->material)->version;

        $this->provider->mapUsing = function () use ($version) {
            $step = $this->mapStep($version, 0)->fresh();
            $step->lease_expires_at = now()->subSecond();
            $step->save();

            throw new MaterialProfileProviderPermanentException;
        };

        $this->assertNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $attempt = MaterialProfileAttempt::query()->firstOrFail();
        $this->assertSame(MaterialProfileAttemptStatus::STARTED, $attempt->status);
        $this->assertNull($attempt->error_code);
        $this->assertNull($attempt->finished_at);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $this->mapStep($version, 0)->fresh()->status);
        $this->assertSame(0, MaterialProfileElement::query()->count());
    }

    public function test_retryable_failure_with_attempts_remaining_closes_only_the_attempt(): void
    {
        $this->provider->mapUsing = function ($request, int $call) {
            if ($call === 1) {
                throw new MaterialProfileProviderTransientException;
            }

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $job = $this->pushedMapJobs()[0];
        $token = $job->stepExecutionToken;

        $this->assertNotNull($this->runProfileJobExpectingRetry($job));

        $attempt = MaterialProfileAttempt::query()->firstOrFail();
        $step = $this->mapStep($version, 0)->fresh();
        $this->assertSame(MaterialProfileAttemptStatus::FAILED, $attempt->status);
        $this->assertSame(1, (int) $attempt->attempt_number);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->status);
        $this->assertNotNull($step->lease_expires_at);
        $this->assertTrue($step->lease_expires_at->gt(now()));
        $this->assertSame($token, (string) $step->step_execution_token);
        $this->assertSame(0, MaterialProfileElement::query()->count());

        $this->runProfileJob($job);
        $this->drainProfileJobs();

        $attempts = MaterialProfileAttempt::query()
            ->where('profile_step_id', $step->profile_step_id)
            ->orderBy('attempt_number')
            ->get();
        $this->assertSame([1, 2], $attempts->pluck('attempt_number')->map(static fn ($n): int => (int) $n)->all());
        $this->assertSame($token, (string) $this->mapStep($version, 0)->fresh()->step_execution_token);
        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
        $this->assertSame(2, $this->provider->mapCalls);
    }

    private function assertAtomicTerminalFailure(
        $version,
        string $stepExecutionToken,
        MaterialProfileAttemptErrorCode $attemptErrorCode,
    ): void {
        $version = $version->fresh();
        $step = $this->mapStep($version, 0)->fresh();
        $attempt = MaterialProfileAttempt::query()->orderByDesc('profile_attempt_id')->firstOrFail();

        $this->assertSame(MaterialProfileAttemptStatus::FAILED, $attempt->status);
        $this->assertSame($attemptErrorCode, $attempt->errorCodeEnum());
        $this->assertSame(MaterialProfileStepStatus::FAILED, $step->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $step->error_code);
        $this->assertNull($step->lease_expires_at);
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertSame(0, MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('status', MaterialProfileStepStatus::PROCESSING)
            ->count());
        $this->assertSame(0, MaterialProfileAttempt::query()
            ->where('status', MaterialProfileAttemptStatus::STARTED)
            ->count());
        $this->assertSame(0, MaterialProfileAttempt::query()
            ->where('status', MaterialProfileAttemptStatus::SUCCEEDED)
            ->count());
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $this->reduceStepOf($version)->fresh()->error_code);

        $claim = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $stepExecutionToken,
        );
        $this->assertSame(MaterialProfileClaimOutcome::Terminal, $claim->outcome);
        $this->assertFalse($claim->shouldRun);

        $this->assertNull(app(BeginMaterialProfileAttempt::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $stepExecutionToken,
            FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
            (string) config('material_profile.primary_model'),
            (string) config('material_profile.map_prompt_version'),
        ));
        $this->assertSame(0, MaterialProfileAttempt::query()
            ->where('status', MaterialProfileAttemptStatus::STARTED)
            ->count());
    }
}
