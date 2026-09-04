<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStartOutcome;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class StartMaterialProfileAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use RunsMaterialProfileWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeProfileProvider();
    }

    public function test_matching_ready_version_is_reused_without_creating_or_dispatching(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Materi ajar tentang fotosintesis.']);
        $ready = $this->completeProfileAnalysis($user, $material);
        $this->assertSame(MaterialProfileStatus::READY, $ready->status);

        $counts = $this->rowCounts();
        Queue::fake();

        $result = $this->startProfileAnalysis($user, $material->fresh());

        $this->assertSame(MaterialProfileStartOutcome::Reused, $result->outcome);
        $this->assertTrue($result->wasReused());
        $this->assertNull($result->dispatch);
        $this->assertSame((int) $ready->profile_version_id, (int) $result->version->profile_version_id);
        $this->assertSame($counts, $this->rowCounts());
        Queue::assertNothingPushed();
    }

    public function test_reuse_does_not_consume_throttle(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Materi ajar reuse.']);
        $this->completeProfileAnalysis($user, $material);

        // One creation used. Reusing many times must not spend the remaining two.
        foreach (range(1, 5) as $ignored) {
            $this->assertTrue($this->startProfileAnalysis($user, $material->fresh())->wasReused());
        }

        $second = Material::factory()->text()->for($user)->create(['content' => 'Materi kedua.']);
        $third = Material::factory()->text()->for($user)->create(['content' => 'Materi ketiga.']);

        $this->assertTrue($this->startProfileAnalysis($user, $second)->wasCreated());
        $this->assertTrue($this->startProfileAnalysis($user, $third)->wasCreated());
        $this->assertSame(3, MaterialProfileVersion::query()->count());
    }

    public function test_force_regeneration_creates_a_new_immutable_version(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Materi ajar regenerasi.']);
        $ready = $this->completeProfileAnalysis($user, $material);
        $before = $this->terminalFingerprint($ready);
        $beforeElements = MaterialProfileElement::query()
            ->where('profile_version_id', $ready->profile_version_id)
            ->orderBy('profile_element_id')
            ->pluck('text')
            ->all();

        $result = $this->startProfileAnalysis($user, $material->fresh(), forceRegenerate: true);

        $this->assertTrue($result->wasCreated());
        $this->assertNotSame((int) $ready->profile_version_id, (int) $result->version->profile_version_id);
        $this->assertSame((int) $ready->version + 1, (int) $result->version->version);
        $this->assertSame(MaterialProfileStatus::QUEUED, $result->version->status);
        $this->assertNotSame((string) $ready->workflow_token, (string) $result->version->workflow_token);

        $this->assertSame($before, $this->terminalFingerprint($ready->fresh()));
        $this->assertSame($beforeElements, MaterialProfileElement::query()
            ->where('profile_version_id', $ready->profile_version_id)
            ->orderBy('profile_element_id')
            ->pluck('text')
            ->all());
    }

    public function test_content_hash_mismatch_creates_a_new_version(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Konten awal materi.']);
        $ready = $this->completeProfileAnalysis($user, $material);

        $material->content = 'Konten materi sudah diubah oleh pemilik.';
        $material->save();

        $result = $this->startProfileAnalysis($user, $material->fresh());

        $this->assertTrue($result->wasCreated());
        $this->assertNotSame((int) $ready->profile_version_id, (int) $result->version->profile_version_id);
        $this->assertSame(MaterialProfileStatus::READY, $ready->fresh()->status);
    }

    public function test_file_hash_mismatch_prevents_reuse(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Materi dengan berkas.']);
        $ready = $this->completeProfileAnalysis($user, $material);
        $this->assertNull($ready->material_file_hash);

        // Same canonical content, but the Material now carries a file hash.
        $material->file_hash = hash('sha256', 'berkas-baru');
        $material->save();

        $result = $this->startProfileAnalysis($user, $material->fresh());

        $this->assertTrue($result->wasCreated());
        $this->assertSame($material->file_hash, (string) $result->version->material_file_hash);
    }

    public function test_extractor_implementation_mismatch_prevents_reuse(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Materi ekstraktor.']);
        $ready = $this->completeProfileAnalysis($user, $material);

        config(['material_profile.extractor_implementation' => 'pdfparser:smalot:3+MaterialExtractorRouter']);

        $result = $this->startProfileAnalysis($user, $material->fresh());

        $this->assertTrue($result->wasCreated());
        $this->assertNotSame((int) $ready->profile_version_id, (int) $result->version->profile_version_id);
        $this->assertSame(
            'pdfparser:smalot:3+MaterialExtractorRouter',
            (string) $result->version->extractor_implementation,
        );
    }

    public function test_in_flight_version_is_not_duplicated(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Materi in flight.']);
        $this->startProfileAnalysis($user, $material);
        $counts = $this->rowCounts();
        Queue::fake();

        foreach ([false, true] as $forceRegenerate) {
            try {
                $this->startProfileAnalysis($user, $material->fresh(), $forceRegenerate);
                $this->fail('Expected an in-flight rejection.');
            } catch (MaterialProfileRejectedException $exception) {
                $this->assertSame(MaterialProfileErrorCode::InFlightExists, $exception->errorCode);
            }
        }

        $this->assertSame($counts, $this->rowCounts());
        Queue::assertNothingPushed();
    }

    public function test_only_the_first_map_job_is_initially_dispatched(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => $this->multiChunkContent(3),
        ]);

        $result = $this->startProfileAnalysis($user, $material);

        $this->assertGreaterThan(1, MaterialProfileChunk::query()->count());
        Queue::assertPushed(AnalyzeMaterialProfileMapJob::class, 1);
        Queue::assertNotPushed(ReduceMaterialProfileJob::class);

        $job = $this->pushedMapJobs()[0];
        $firstStep = $this->mapStep($result->version, 0);
        $this->assertSame((int) $firstStep->profile_step_id, $job->profileStepId);
        $this->assertSame((string) $result->version->workflow_token, $job->workflowToken);
        $this->assertSame((string) $firstStep->step_execution_token, $job->stepExecutionToken);

        // Later map Steps stay queued without a token until their turn.
        $this->assertNull($this->mapStep($result->version, 1)->step_execution_token);
        $this->assertNull($this->mapStep($result->version, 1)->step_queued_at);
        $this->assertNull($this->reduceStepOf($result->version)->step_execution_token);
    }

    public function test_three_new_analyses_per_hour_are_allowed_and_fourth_is_rejected(): void
    {
        $user = User::factory()->create();
        $materials = [];

        foreach (range(1, 4) as $index) {
            $materials[$index] = Material::factory()->text()->for($user)->create([
                'content' => 'Materi nomor '.$index.' untuk pengujian throttle.',
            ]);
        }

        foreach (range(1, 3) as $index) {
            $this->assertTrue($this->startProfileAnalysis($user, $materials[$index])->wasCreated());
        }

        $counts = $this->rowCounts();
        Queue::fake();

        try {
            $this->startProfileAnalysis($user, $materials[4]);
            $this->fail('Expected a throttle rejection.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::ThrottleExceeded, $exception->errorCode);
        }

        $this->assertSame($counts, $this->rowCounts());
        $this->assertSame(3, MaterialProfileVersion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_throttle_counts_workflows_that_later_failed(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $index) {
            $material = Material::factory()->text()->for($user)->create([
                'content' => 'Materi gagal nomor '.$index.'.',
            ]);
            $version = $this->startProfileAnalysis($user, $material)->version;
            $version->status = MaterialProfileStatus::FAILED;
            $version->failed_at = now();
            $version->error_code = MaterialProfileErrorCode::ProviderFailed->value;
            $version->save();
            MaterialProfileStep::query()
                ->where('profile_version_id', $version->profile_version_id)
                ->update(['status' => 'failed', 'lease_expires_at' => null]);
        }

        $fourth = Material::factory()->text()->for($user)->create(['content' => 'Materi keempat.']);

        $this->expectException(MaterialProfileRejectedException::class);
        $this->startProfileAnalysis($user, $fourth);
    }

    public function test_rolling_throttle_becomes_available_after_one_hour(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $index) {
            $material = Material::factory()->text()->for($user)->create([
                'content' => 'Materi rolling nomor '.$index.'.',
            ]);
            $this->startProfileAnalysis($user, $material);
        }

        $fourth = Material::factory()->text()->for($user)->create(['content' => 'Materi rolling keempat.']);

        try {
            $this->startProfileAnalysis($user, $fourth);
            $this->fail('Expected a throttle rejection.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::ThrottleExceeded, $exception->errorCode);
        }

        $this->travel(61)->minutes();

        $this->assertTrue($this->startProfileAnalysis($user, $fourth->fresh())->wasCreated());
        $this->assertSame(4, MaterialProfileVersion::query()->count());
    }

    public function test_throttle_is_scoped_per_user(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach (range(1, 3) as $index) {
            $material = Material::factory()->text()->for($first)->create(['content' => 'Materi A'.$index.'.']);
            $this->startProfileAnalysis($first, $material);
        }

        $otherMaterial = Material::factory()->text()->for($second)->create(['content' => 'Materi B.']);

        $this->assertTrue($this->startProfileAnalysis($second, $otherMaterial)->wasCreated());
    }

    public function test_ineligible_material_cannot_start_or_regenerate(): void
    {
        $user = User::factory()->create();
        $archived = Material::factory()->text()->for($user)->archived()->create(['content' => 'Materi arsip.']);

        foreach ([false, true] as $forceRegenerate) {
            try {
                $this->startProfileAnalysis($user, $archived, $forceRegenerate);
                $this->fail('Expected an eligibility rejection.');
            } catch (MaterialProfileRejectedException $exception) {
                $this->assertSame(MaterialProfileErrorCode::MaterialIneligible, $exception->errorCode);
            }
        }

        $this->assertSame(0, MaterialProfileVersion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_non_owner_cannot_start_through_the_domain_action(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $material = Material::factory()->text()->for($owner)->create(['content' => 'Materi milik orang lain.']);

        try {
            $this->startProfileAnalysis($stranger, $material);
            $this->fail('Expected an ownership rejection.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::MaterialIneligible, $exception->errorCode);
        }

        $this->assertSame(0, MaterialProfileVersion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_start_and_completion_write_no_usage_logs(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Materi tanpa kuota.']);

        $version = $this->completeProfileAnalysis($user, $material);

        $this->assertSame(MaterialProfileStatus::READY, $version->status);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    /**
     * @return array<string, string|null>
     */
    private function terminalFingerprint(MaterialProfileVersion $version): array
    {
        return [
            'status' => $version->status->value,
            'version' => (string) $version->version,
            'workflow_token' => (string) $version->workflow_token,
            'material_content_hash' => (string) $version->material_content_hash,
            'completed_at' => $version->completed_at?->toIso8601String(),
            'failed_at' => $version->failed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'versions' => MaterialProfileVersion::query()->count(),
            'chunks' => MaterialProfileChunk::query()->count(),
            'steps' => MaterialProfileStep::query()->count(),
            'elements' => MaterialProfileElement::query()->count(),
            'attempts' => MaterialProfileAttempt::query()->count(),
            'usage' => AiUsageLog::query()->count(),
        ];
    }

    public function test_reduce_step_purpose_enum_is_used_for_the_final_step(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Materi enum langkah.']);
        $version = $this->startProfileAnalysis($user, $material)->version;

        $this->assertSame(
            MaterialProfileStepPurpose::REDUCE,
            $this->reduceStepOf($version)->purpose,
        );
    }
}
