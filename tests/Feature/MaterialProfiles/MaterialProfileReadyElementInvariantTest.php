<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileReadyElementInvariantTest extends TestCase
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

    public function test_extracted_element_missing_evidence_rolls_back_reduce_success(): void
    {
        $this->assertReduceFinalizationRollsBack(function (Material $material) {
            MaterialProfileElement::query()
                ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
                ->update(['evidence_excerpt' => null]);
        });
    }

    public function test_extracted_element_missing_offsets_rolls_back_reduce_success(): void
    {
        $this->assertReduceFinalizationRollsBack(function (): void {
            MaterialProfileElement::query()
                ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
                ->update([
                    'char_start' => null,
                    'char_end' => null,
                ]);
        });
    }

    public function test_extracted_element_with_a_foreign_chunk_rolls_back_reduce_success(): void
    {
        $this->assertReduceFinalizationRollsBack(function () {
            $other = Material::factory()->text()->for($this->user)->create(['content' => 'Materi asing.']);
            $foreignVersion = $this->startProfileAnalysis($this->user, $other)->version;

            MaterialProfileElement::query()
                ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
                ->update(['source_chunk_id' => $foreignVersion->chunks()->value('profile_chunk_id')]);
        });
    }

    public function test_suggested_element_carrying_a_chunk_rolls_back_reduce_success(): void
    {
        $this->assertReduceFinalizationRollsBack(function ($material, $version): void {
            MaterialProfileElement::factory()->create([
                'profile_version_id' => $version->profile_version_id,
                'source_chunk_id' => $version->chunks()->value('profile_chunk_id'),
                'origin' => MaterialProfileElementOrigin::SUGGESTED,
                'text' => 'Saran tidak valid',
                'sort_order' => 50,
            ]);
        });
    }

    public function test_suggested_element_carrying_evidence_rolls_back_reduce_success(): void
    {
        $this->assertReduceFinalizationRollsBack(function ($material, $version): void {
            MaterialProfileElement::factory()->create([
                'profile_version_id' => $version->profile_version_id,
                'origin' => MaterialProfileElementOrigin::SUGGESTED,
                'text' => 'Saran dengan bukti',
                'evidence_excerpt' => 'bukti',
                'evidence_locator' => 'core-0:0-5',
                'char_start' => 0,
                'char_end' => 5,
                'sort_order' => 51,
            ]);
        });
    }

    public function test_malformed_evidence_locator_rolls_back_reduce_success(): void
    {
        $this->assertReduceFinalizationRollsBack(function (): void {
            MaterialProfileElement::query()
                ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
                ->update(['evidence_locator' => 'core-0']);
        });
    }

    /**
     * @param  callable(Material, MaterialProfileVersion): void  $corrupt
     */
    private function assertReduceFinalizationRollsBack(callable $corrupt): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang gaya gravitasi bumi dan penerapannya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $corrupt($material, $version);

        $this->runProfileJob($this->pushedReduceJobs()[0]);

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ValidationFailed->value, (string) $version->error_code);
        $this->assertNull($version->completed_at);
        $this->assertSame(MaterialProfileStepStatus::FAILED, $this->reduceStepOf($version)->fresh()->status);
        $this->assertSame(0, MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::SUGGESTED)
            ->where('text', 'Cakupan materi keseluruhan')
            ->count());
        $this->assertSame(0, MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('sort_order', '>=', 1_000_000)
            ->count());
    }
}
