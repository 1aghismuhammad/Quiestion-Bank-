<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\BeginMaterialProfileAttempt;
use App\Actions\MaterialProfiles\ClaimMaterialProfileStep;
use App\Actions\MaterialProfiles\RecoverStaleMaterialProfiles;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileMapNextStepTopologyTest extends TestCase
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

    public function test_missing_reduce_step_during_final_map_persistence_fails_the_workflow(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->twoChunkMaterial())->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->reduceStepOf($version)->delete();
        $elementsBefore = MaterialProfileElement::query()->count();
        $mapJobsBefore = count($this->pushedMapJobs());

        $this->runProfileJob($this->pushedMapJobs()[1]);

        $this->assertBrokenMapFinalization(
            $version->fresh(),
            $this->mapStep($version, 1),
            $elementsBefore,
            $mapJobsBefore,
        );
        $this->assertNull(
            MaterialProfileStep::query()
                ->where('profile_version_id', $version->profile_version_id)
                ->where('purpose', MaterialProfileStepPurpose::REDUCE)
                ->first(),
        );
    }

    public function test_next_step_workflow_token_mismatch_fails_the_workflow(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->twoChunkMaterial())->version;
        $next = $this->mapStep($version, 1);
        $next->workflow_token = (string) Str::uuid();
        $next->save();

        $this->assertBrokenFirstMap($version);
    }

    public function test_malformed_next_map_chunk_identity_fails_the_workflow(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->twoChunkMaterial())->version;
        $next = $this->mapStep($version, 1);
        $next->profile_chunk_id = null;
        $next->save();

        $this->assertBrokenFirstMap($version);
    }

    public function test_next_step_unexpectedly_processing_fails_the_workflow(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->twoChunkMaterial())->version;
        $next = $this->mapStep($version, 1);
        $next->status = MaterialProfileStepStatus::PROCESSING;
        $next->lease_expires_at = now()->addSeconds(120);
        $next->save();

        $elementsBefore = MaterialProfileElement::query()->count();
        $mapJobsBefore = count($this->pushedMapJobs());
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->assertBrokenMapFinalization(
            $version->fresh(),
            $this->mapStep($version, 0),
            $elementsBefore,
            $mapJobsBefore,
        );
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $this->mapStep($version, 1)->fresh()->error_code);
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $this->reduceStepOf($version)->fresh()->error_code);
    }

    public function test_earlier_map_changed_from_ready_back_to_queued_rejects_map_success(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->threeChunkMaterial())->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->provider->mapUsing = function ($request) use ($version) {
            $earlier = $this->mapStep($version, 0)->fresh();
            $earlier->status = MaterialProfileStepStatus::QUEUED;
            $earlier->save();

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $this->assertBrokenLaterMap($version, 1);
    }

    public function test_later_map_already_marked_ready_rejects_map_success(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->threeChunkMaterial())->version;
        $later = $this->mapStep($version, 2);
        $later->status = MaterialProfileStepStatus::READY;
        $later->save();

        $this->assertBrokenFirstMap($version);
    }

    public function test_invalid_map_step_index_rejects_map_success(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->twoChunkMaterial())->version;
        $next = $this->mapStep($version, 1);
        $next->step_index = 99;
        $next->save();

        $elementsBefore = MaterialProfileElement::query()->count();
        $mapJobsBefore = count($this->pushedMapJobs());
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->assertBrokenMapFinalization(
            $version->fresh(),
            $this->mapStep($version, 0),
            $elementsBefore,
            $mapJobsBefore,
        );
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $next->fresh()->error_code);
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $this->reduceStepOf($version)->fresh()->error_code);
    }

    public function test_duplicate_map_step_index_is_rejected_by_schema_where_simulation_is_blocked(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->twoChunkMaterial())->version;
        $next = $this->mapStep($version, 1);

        $this->expectException(QueryException::class);
        $next->step_index = 0;
        $next->save();
    }

    public function test_prior_map_failed_rejects_map_success(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->threeChunkMaterial())->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->provider->mapUsing = function ($request) use ($version) {
            $prior = $this->mapStep($version, 0)->fresh();
            $prior->status = MaterialProfileStepStatus::FAILED;
            $prior->error_code = MaterialProfileErrorCode::ProviderFailed->value;
            $prior->save();

            return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
        };

        $this->assertBrokenLaterMap($version, 1);
    }

    public function test_step_with_a_foreign_workflow_token_rejects_map_success(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->threeChunkMaterial())->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $earlier = $this->mapStep($version, 0)->fresh();
        $earlier->workflow_token = (string) Str::uuid();
        $earlier->save();

        $this->assertBrokenLaterMap($version, 1);
    }

    public function test_malformed_reduce_token_rejects_map_success(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->twoChunkMaterial())->version;
        $reduce = $this->reduceStepOf($version);
        $reduce->workflow_token = (string) Str::uuid();
        $reduce->save();

        $this->assertBrokenFirstMap($version);
    }

    public function test_legal_immediate_next_map_is_dispatched_exactly_once(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->threeChunkMaterial())->version;
        $this->assertCount(1, $this->pushedMapJobs());
        Queue::assertNotPushed(ReduceMaterialProfileJob::class);

        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->assertCount(2, $this->pushedMapJobs());
        $this->assertSame(
            (int) $this->mapStep($version, 1)->profile_step_id,
            $this->pushedMapJobs()[1]->profileStepId,
        );
        Queue::assertNotPushed(ReduceMaterialProfileJob::class);
        $this->assertSame(MaterialProfileStepStatus::READY, $this->mapStep($version, 0)->fresh()->status);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $this->mapStep($version, 1)->fresh()->status);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $this->mapStep($version, 2)->fresh()->status);

        $this->runProfileJob($this->pushedMapJobs()[1]);

        $this->assertCount(3, $this->pushedMapJobs());
        $this->assertSame(
            (int) $this->mapStep($version, 2)->profile_step_id,
            $this->pushedMapJobs()[2]->profileStepId,
        );
        Queue::assertNotPushed(ReduceMaterialProfileJob::class);
        $this->assertSame(2, $this->provider->mapCalls);
    }

    public function test_final_map_dispatches_exactly_the_sole_reduce_step(): void
    {
        $version = $this->startProfileAnalysis($this->user, $this->twoChunkMaterial())->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);
        Queue::assertNotPushed(ReduceMaterialProfileJob::class);

        $this->runProfileJob($this->pushedMapJobs()[1]);

        $this->assertCount(2, $this->pushedMapJobs());
        Queue::assertPushed(ReduceMaterialProfileJob::class, 1);
        $this->assertSame(
            (int) $this->reduceStepOf($version)->profile_step_id,
            $this->pushedReduceJobs()[0]->profileStepId,
        );
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $this->reduceStepOf($version)->fresh()->status);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
    }

    private function assertBrokenFirstMap($version): void
    {
        $elementsBefore = MaterialProfileElement::query()->count();
        $mapJobsBefore = count($this->pushedMapJobs());
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->assertBrokenMapFinalization(
            $version->fresh(),
            $this->mapStep($version, 0),
            $elementsBefore,
            $mapJobsBefore,
        );
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $this->mapStep($version, 1)->fresh()->error_code);
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $this->reduceStepOf($version)->fresh()->error_code);
    }

    private function assertBrokenLaterMap($version, int $stepIndex): void
    {
        $elementsBefore = MaterialProfileElement::query()->count();
        $mapJobsBefore = count($this->pushedMapJobs());
        $succeededBefore = MaterialProfileAttempt::query()
            ->where('status', MaterialProfileAttemptStatus::SUCCEEDED)
            ->count();

        $this->runProfileJob($this->pushedMapJobs()[$stepIndex]);

        $this->assertBrokenMapFinalization(
            $version->fresh(),
            $this->mapStep($version, $stepIndex),
            $elementsBefore,
            $mapJobsBefore,
        );
        $this->assertSame(
            $succeededBefore,
            MaterialProfileAttempt::query()
                ->where('status', MaterialProfileAttemptStatus::SUCCEEDED)
                ->count(),
        );
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, (string) $this->reduceStepOf($version)->fresh()->error_code);
    }

    private function assertBrokenMapFinalization(
        $version,
        MaterialProfileStep $active,
        int $elementsBefore,
        int $mapJobsBefore,
    ): void {
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ValidationFailed->value, (string) $version->error_code);
        $this->assertSame(MaterialProfileStepStatus::FAILED, $active->fresh()->status);
        $this->assertSame(MaterialProfileErrorCode::ValidationFailed->value, (string) $active->fresh()->error_code);
        $this->assertNull($active->fresh()->lease_expires_at);
        $this->assertSame($elementsBefore, MaterialProfileElement::query()->count());
        $latest = MaterialProfileAttempt::query()->orderByDesc('profile_attempt_id')->firstOrFail();
        $this->assertSame(MaterialProfileAttemptStatus::FAILED, $latest->status);
        $this->assertSame(MaterialProfileAttemptErrorCode::ValidationFailed, $latest->errorCodeEnum());
        $this->assertSame(0, MaterialProfileAttempt::query()
            ->where('profile_step_id', $active->profile_step_id)
            ->where('status', MaterialProfileAttemptStatus::SUCCEEDED)
            ->count());
        $this->assertSame(0, MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('status', MaterialProfileStepStatus::PROCESSING)
            ->count());
        $this->assertCount($mapJobsBefore, $this->pushedMapJobs());
        Queue::assertNotPushed(ReduceMaterialProfileJob::class);
        $this->assertSame(0, app(RecoverStaleMaterialProfiles::class)->handle());
        $this->assertSame(MaterialProfileErrorCode::ValidationFailed->value, (string) $version->fresh()->error_code);

        $token = (string) $active->fresh()->step_execution_token;
        $claim = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $active->profile_step_id,
            (string) $version->workflow_token,
            $token,
        );
        $this->assertSame(MaterialProfileClaimOutcome::Terminal, $claim->outcome);
        $this->assertFalse($claim->shouldRun);
        $this->assertNull(app(BeginMaterialProfileAttempt::class)->handle(
            (int) $version->profile_version_id,
            (int) $active->profile_step_id,
            (string) $version->workflow_token,
            $token,
            FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
            (string) config('material_profile.primary_model'),
            (string) config('material_profile.map_prompt_version'),
        ));
    }

    private function twoChunkMaterial(): Material
    {
        return Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent(2),
        ]);
    }

    private function threeChunkMaterial(): Material
    {
        return Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent(3),
        ]);
    }
}
