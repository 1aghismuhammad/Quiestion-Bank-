<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Data\MaterialProfiles\ExtractedProfileCandidate;
use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileStatus;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileMapEvidenceCrLfTest extends TestCase
{
    use RefreshDatabase, RunsMaterialProfileWorkflows;

    public function test_evidence_with_lf_matches_core_with_crlf(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $content = "Baris pertama.\r\nBaris kedua.";
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);

        $provider = $this->fakeProfileProvider();
        $provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            new ExtractedProfileCandidate(
                MaterialProfileElementKind::TOPIC->value,
                'Topik',
                "Baris pertama.\nBaris kedua.", // Gemini returns LF
                0,
                28
            ),
        ]);

        $version = $this->startProfileAnalysis($user, $material)->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);

        $element = MaterialProfileElement::query()->firstOrFail();
        $this->assertSame("Baris pertama.\r\nBaris kedua.", $element->evidence_excerpt);
    }
}
