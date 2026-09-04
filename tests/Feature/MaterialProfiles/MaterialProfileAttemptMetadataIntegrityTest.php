<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Data\MaterialProfiles\ProfileMapResult;
use App\Data\MaterialProfiles\ProfileProviderAttemptMetadata;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\User;
use App\Support\MaterialProfiles\MaterialProfileBudgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileAttemptMetadataIntegrityTest extends TestCase
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

    public function test_provider_metadata_mismatch_rejects_the_complete_success_result(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang gaya gravitasi bumi dan penerapannya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function ($request) {
            $valid = FakeMaterialProfileAnalysisProvider::defaultMapResult($request);

            return new ProfileMapResult($valid->candidates, new ProfileProviderAttemptMetadata(
                provider: 'google_gemini',
                model: $request->model,
                promptVersion: $request->promptVersion,
                purpose: MaterialProfileStepPurpose::MAP,
                inputTokens: 10,
                outputTokens: 4,
                totalTokens: 14,
                latencyMs: 3,
            ));
        };

        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $this->mapStep($version, 0)->fresh()->status);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame([], $this->pushedReduceJobs());
        $this->assertSame(
            MaterialProfileAttemptStatus::FAILED,
            MaterialProfileAttempt::query()->firstOrFail()->status,
        );
        $this->assertSame(
            FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
            MaterialProfileAttempt::query()->value('provider'),
        );
    }

    public function test_oversized_token_counts_reject_the_complete_success_result(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang gaya gravitasi bumi dan penerapannya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function ($request) {
            $valid = FakeMaterialProfileAnalysisProvider::defaultMapResult($request);

            return new ProfileMapResult($valid->candidates, new ProfileProviderAttemptMetadata(
                provider: FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
                model: $request->model,
                promptVersion: $request->promptVersion,
                purpose: MaterialProfileStepPurpose::MAP,
                inputTokens: MaterialProfileBudgets::UNSIGNED_INT_MAX + 1,
                outputTokens: 4,
                totalTokens: 14,
                latencyMs: 3,
            ));
        };

        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame([], $this->pushedReduceJobs());
    }

    public function test_negative_latency_rejects_the_complete_success_result(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang gaya gravitasi bumi dan penerapannya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function ($request) {
            $valid = FakeMaterialProfileAnalysisProvider::defaultMapResult($request);

            return new ProfileMapResult($valid->candidates, new ProfileProviderAttemptMetadata(
                provider: FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
                model: $request->model,
                promptVersion: $request->promptVersion,
                purpose: MaterialProfileStepPurpose::MAP,
                inputTokens: 10,
                outputTokens: 4,
                totalTokens: 14,
                latencyMs: -1,
            ));
        };

        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
    }

    public function test_purpose_mismatch_rejects_the_complete_success_result(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang gaya gravitasi bumi dan penerapannya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function ($request) {
            $valid = FakeMaterialProfileAnalysisProvider::defaultMapResult($request);

            return new ProfileMapResult($valid->candidates, new ProfileProviderAttemptMetadata(
                provider: FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
                model: $request->model,
                promptVersion: $request->promptVersion,
                purpose: MaterialProfileStepPurpose::REDUCE,
                inputTokens: 10,
                outputTokens: 4,
                totalTokens: 14,
                latencyMs: 3,
            ));
        };

        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame([], $this->pushedReduceJobs());
    }

    public function test_oversized_model_identity_is_rejected_before_the_attempt_insert(): void
    {
        config(['material_profile.primary_model' => str_repeat('m', MaterialProfileBudgets::MODEL_MAX_LENGTH + 1)]);

        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang gaya gravitasi bumi dan penerapannya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->assertSame(0, MaterialProfileAttempt::query()->count());
        $this->assertSame(0, $this->provider->mapCalls);
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(MaterialProfileErrorCode::ValidationFailed->value, (string) $version->fresh()->error_code);
        $this->assertSame([], $this->pushedReduceJobs());
    }
}
