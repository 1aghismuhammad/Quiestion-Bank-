<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\QueueMaterialProfileAnalysis;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\TestCase;

class QueueMaterialProfileAnalysisTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;

    public function test_queue_creates_chunks_map_steps_one_reduce_and_one_workflow_token(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('c', 12_001),
        ]);

        $version = $this->queueProfile($user, $material);

        $this->assertSame(MaterialProfileStatus::QUEUED, $version->status);
        $this->assertSame($user->id, $version->user_id);
        $this->assertNotSame('', $version->workflow_token);
        $this->assertSame(1, $version->version);
        $this->assertSame(2, $version->chunks()->count());
        $this->assertSame(2, $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->count());
        $this->assertSame(1, $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->count());

        $firstMap = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->orderBy('step_index')->firstOrFail();
        $secondMap = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->orderBy('step_index')->offset(1)->firstOrFail();
        $reduce = $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->firstOrFail();

        $this->assertSame(0, $firstMap->step_index);
        $this->assertSame(1, $secondMap->step_index);
        $this->assertSame(0, $reduce->step_index);
        $this->assertNotNull($firstMap->profile_chunk_id);
        $this->assertNull($reduce->profile_chunk_id);
        $this->assertNotNull($firstMap->step_queued_at);
        $this->assertNull($secondMap->step_queued_at);
        $this->assertSame($version->workflow_token, $firstMap->workflow_token);
        $this->assertSame($version->workflow_token, $reduce->workflow_token);
        $this->assertCount(1, $version->steps()->pluck('workflow_token')->unique());
        $this->assertNull($firstMap->step_execution_token);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $firstMap->status);

        Queue::assertNothingPushed();
    }

    public function test_second_in_flight_queue_is_rejected_without_partial_rows(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Satu alur.']);

        $first = $this->queueProfile($user, $material);

        try {
            app(QueueMaterialProfileAnalysis::class)->handle($user, $material);
            $this->fail('Expected in-flight rejection.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::InFlightExists, $exception->errorCode);
        }

        $this->assertSame(1, $user->profileVersions()->count());
        $this->assertSame($first->profile_version_id, $user->profileVersions()->firstOrFail()->profile_version_id);
        $this->assertSame(1, MaterialProfileStep::query()->where('purpose', MaterialProfileStepPurpose::REDUCE)->count());
    }

    public function test_relationships_and_casts_are_complete(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Relasi.']);
        $version = $this->queueProfile($user, $material);

        $this->assertTrue($material->profileVersions->contains($version));
        $this->assertTrue($user->profileVersions->contains($version));
        $this->assertSame($material->material_id, $version->material->material_id);
        $this->assertSame($user->id, $version->user->id);
        $this->assertNotNull($version->chunks->first()?->mapStep);
        $this->assertSame($version->profile_version_id, $version->steps->first()?->version->profile_version_id);
    }
}
