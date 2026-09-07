<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use App\Support\MaterialProfiles\MaterialProfileUnexpectedProviderFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileUnexpectedProviderFailureTest extends TestCase
{
    use RefreshDatabase;
    use RunsMaterialProfileWorkflows;

    private const SECRET = 'SECRET_PROVIDER_THROWABLE_do-not-persist';

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

    public function test_unexpected_map_throwable_fails_the_attempt_without_leaking_the_raw_message(): void
    {
        $this->provider->mapUsing = static fn () => throw new RuntimeException(self::SECRET);

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $this->assertNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $attempt = MaterialProfileAttempt::query()->firstOrFail();
        $this->assertSame(MaterialProfileAttemptStatus::FAILED, $attempt->status);
        $this->assertSame(MaterialProfileAttemptErrorCode::ProviderHttp, $attempt->errorCodeEnum());
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->fresh()->error_code);
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSecretAbsent();
    }

    public function test_unexpected_reduce_throwable_fails_the_attempt_without_leaking_the_raw_message(): void
    {
        $this->provider->reduceUsing = static fn () => throw new RuntimeException(self::SECRET);

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->assertNull($this->runProfileJobExpectingRetry($this->pushedReduceJobs()[0]));

        $reduceAttempt = MaterialProfileAttempt::query()
            ->where('profile_step_id', $this->reduceStepOf($version)->profile_step_id)
            ->firstOrFail();
        $this->assertSame(MaterialProfileAttemptStatus::FAILED, $reduceAttempt->status);
        $this->assertSame(MaterialProfileAttemptErrorCode::ProviderHttp, $reduceAttempt->errorCodeEnum());
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(0, MaterialProfileElement::query()->where('origin', 'suggested')->count());
        Queue::assertPushed(ReduceMaterialProfileJob::class, 1);
        $this->assertSecretAbsent();
    }

    public function test_lost_authority_after_attempt_creation_writes_nothing_for_an_unexpected_throwable(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->material)->version;

        $this->provider->mapUsing = function () use ($version) {
            $step = $this->mapStep($version, 0)->fresh();
            $step->lease_expires_at = now()->subSecond();
            $step->save();

            throw new RuntimeException(self::SECRET);
        };

        $this->assertNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $attempt = MaterialProfileAttempt::query()->firstOrFail();
        $this->assertSame(MaterialProfileAttemptStatus::STARTED, $attempt->status);
        $this->assertNull($attempt->error_code);
        $this->assertNull($attempt->finished_at);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $this->mapStep($version, 0)->fresh()->status);
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSecretAbsent();
    }

    public function test_recognized_transient_connection_failure_still_retries(): void
    {
        $this->provider->mapUsing = function ($request, int $call) {
            if ($call === 1) {
                throw new ConnectionException('cURL error 28: '.self::SECRET);
            }

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $version = $this->startProfileAnalysis($this->user, $this->material)->version;
        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $attempt = MaterialProfileAttempt::query()->firstOrFail();
        $this->assertSame(MaterialProfileAttemptStatus::FAILED, $attempt->status);
        $this->assertSame(MaterialProfileAttemptErrorCode::ProviderTimeout, $attempt->errorCodeEnum());
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSecretAbsent();

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->drainProfileJobs();
        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
    }

    public function test_classifier_maps_unknown_failures_to_a_permanent_sanitized_code(): void
    {
        $mapped = MaterialProfileUnexpectedProviderFailure::classify(new RuntimeException(self::SECRET));

        $this->assertSame(MaterialProfileAttemptErrorCode::ProviderHttp, $mapped->attemptErrorCode);
        $this->assertFalse($mapped->isRetryable());
        $this->assertStringNotContainsString(self::SECRET, $mapped->getMessage());
    }

    private function assertSecretAbsent(): void
    {
        foreach (MaterialProfileAttempt::query()->get() as $attempt) {
            $this->assertStringNotContainsString(self::SECRET, (string) json_encode($attempt->getAttributes()));
        }

        foreach (MaterialProfileVersion::query()->get() as $version) {
            $this->assertStringNotContainsString(self::SECRET, (string) ($version->error_code ?? ''));
            $this->assertStringNotContainsString(self::SECRET, (string) ($version->error_message ?? ''));
        }

        foreach (MaterialProfileStep::query()->get() as $step) {
            $this->assertStringNotContainsString(self::SECRET, (string) ($step->error_code ?? ''));
            $this->assertStringNotContainsString(self::SECRET, (string) ($step->error_message ?? ''));
        }
    }
}
