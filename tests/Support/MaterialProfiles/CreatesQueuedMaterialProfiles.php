<?php

declare(strict_types=1);

namespace Tests\Support\MaterialProfiles;

use App\Actions\MaterialProfiles\ClaimMaterialProfileStep;
use App\Actions\MaterialProfiles\QueueMaterialProfileAnalysis;
use App\Actions\MaterialProfiles\ValidateProfileMapCandidates;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Support\Str;

trait CreatesQueuedMaterialProfiles
{
    protected function queueProfile(User $user, Material $material): MaterialProfileVersion
    {
        return app(QueueMaterialProfileAnalysis::class)->handle($user, $material);
    }

    protected function claimStep(
        MaterialProfileVersion $version,
        MaterialProfileStep $step,
        string $stepExecutionToken,
    ): MaterialProfileClaimOutcome {
        return app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $stepExecutionToken,
        )->outcome;
    }

    protected function markStepReadyWithSucceededAttempt(MaterialProfileStep $step): MaterialProfileAttempt
    {
        $step->status = MaterialProfileStepStatus::READY;
        $step->lease_expires_at = null;
        $step->save();

        return MaterialProfileAttempt::factory()->create([
            'profile_version_id' => $step->profile_version_id,
            'profile_step_id' => $step->profile_step_id,
            'purpose' => $step->purpose,
            'status' => MaterialProfileAttemptStatus::SUCCEEDED,
            'error_code' => null,
        ]);
    }

    protected function addValidExtractedElement(MaterialProfileVersion $version, Material $material): MaterialProfileElement
    {
        /** @var MaterialProfileChunk $chunk */
        $chunk = $version->chunks()->orderBy('chunk_index')->firstOrFail();
        $content = (string) $material->content;
        $excerpt = mb_substr(
            $content,
            (int) $chunk->char_start,
            (int) $chunk->char_end - (int) $chunk->char_start,
            'UTF-8',
        );

        return MaterialProfileElement::factory()->create([
            'profile_version_id' => $version->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'text' => 'Topik utama',
            'evidence_excerpt' => $excerpt,
            'evidence_locator' => ValidateProfileMapCandidates::evidenceLocator(
                (int) $chunk->chunk_index,
                (int) $chunk->char_start,
                (int) $chunk->char_end,
            ),
            'char_start' => $chunk->char_start,
            'char_end' => $chunk->char_end,
            'sort_order' => 0,
        ]);
    }

    protected function completeQueuedProfileForReady(MaterialProfileVersion $version, Material $material): void
    {
        $maps = $version->steps()
            ->where('purpose', MaterialProfileStepPurpose::MAP)
            ->orderBy('step_index')
            ->get();

        foreach ($maps as $map) {
            $token = (string) Str::uuid();
            $this->assertSame(MaterialProfileClaimOutcome::Claimed, $this->claimStep($version, $map, $token));
            $this->markStepReadyWithSucceededAttempt($map->fresh());
        }

        $reduce = $version->steps()
            ->where('purpose', MaterialProfileStepPurpose::REDUCE)
            ->firstOrFail();
        $this->assertSame(
            MaterialProfileClaimOutcome::Claimed,
            $this->claimStep($version->fresh(), $reduce, (string) Str::uuid()),
        );
        $this->markStepReadyWithSucceededAttempt($reduce->fresh());
        $this->addValidExtractedElement($version->fresh(), $material);
    }

    protected function assertNoProfileRows(): void
    {
        $this->assertSame(0, MaterialProfileVersion::query()->count());
        $this->assertSame(0, MaterialProfileChunk::query()->count());
        $this->assertSame(0, MaterialProfileStep::query()->count());
        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(0, MaterialProfileAttempt::query()->count());
    }
}
