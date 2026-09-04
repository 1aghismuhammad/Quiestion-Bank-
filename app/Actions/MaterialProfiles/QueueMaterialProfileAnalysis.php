<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\ProfileChunkSplit;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use App\Services\Materials\Profile\SplitMaterialContentIntoProfileChunks;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueMaterialProfileAnalysis
{
    use LocksMaterialProfileWorkflow;

    public function __construct(
        private AssertMaterialEligibleForProfileAnalysis $assertEligible,
        private MaterialContentHasher $hasher,
        private SplitMaterialContentIntoProfileChunks $splitter,
    ) {}

    public function handle(User $user, Material $material): MaterialProfileVersion
    {
        return DB::transaction(function () use ($user, $material): MaterialProfileVersion {
            $lockedMaterial = $this->lockUserAndMaterial((int) $user->id, (int) $material->material_id);

            if ((int) $lockedMaterial->user_id !== (int) $user->id) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialIneligible);
            }

            $this->assertEligible->handle($lockedMaterial);

            $inFlight = MaterialProfileVersion::query()
                ->where('material_id', $lockedMaterial->material_id)
                ->whereIn('status', [
                    MaterialProfileStatus::QUEUED->value,
                    MaterialProfileStatus::PROCESSING->value,
                ])
                ->lockForUpdate()
                ->exists();

            if ($inFlight) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::InFlightExists);
            }

            $content = (string) $lockedMaterial->content;
            $contentHash = $this->hasher->hash($content);
            $splits = $this->splitter->handle($content);
            $workflowToken = (string) Str::uuid();
            $nextVersion = (int) MaterialProfileVersion::query()
                ->where('material_id', $lockedMaterial->material_id)
                ->max('version');

            $profile = MaterialProfileVersion::query()->create([
                'material_id' => $lockedMaterial->material_id,
                'user_id' => $lockedMaterial->user_id,
                'version' => $nextVersion + 1,
                'status' => MaterialProfileStatus::QUEUED,
                'workflow_token' => $workflowToken,
                'queued_at' => now(),
                'material_content_hash' => $contentHash,
                'material_file_hash' => $lockedMaterial->file_hash,
                'extractor_implementation' => (string) config('material_profile.extractor_implementation'),
            ]);

            foreach ($splits as $index => $split) {
                $this->createMapStep($profile, $workflowToken, $split, $index === 0);
            }

            MaterialProfileStep::query()->create([
                'profile_version_id' => $profile->profile_version_id,
                'purpose' => MaterialProfileStepPurpose::REDUCE,
                'step_index' => 0,
                'profile_chunk_id' => null,
                'status' => MaterialProfileStepStatus::QUEUED,
                'workflow_token' => $workflowToken,
                'step_queued_at' => null,
            ]);

            return $profile->refresh();
        });
    }

    private function createMapStep(
        MaterialProfileVersion $profile,
        string $workflowToken,
        ProfileChunkSplit $split,
        bool $isFirst,
    ): void {
        $chunk = MaterialProfileChunk::query()->create([
            'profile_version_id' => $profile->profile_version_id,
            'chunk_index' => $split->chunkIndex,
            'char_start' => $split->charStart,
            'char_end' => $split->charEnd,
            'overlap_before_start' => $split->overlapBeforeStart,
            'overlap_before_end' => $split->overlapBeforeEnd,
            'core_text_hash' => $split->coreTextHash,
            'required' => true,
        ]);

        MaterialProfileStep::query()->create([
            'profile_version_id' => $profile->profile_version_id,
            'purpose' => MaterialProfileStepPurpose::MAP,
            'step_index' => $split->chunkIndex,
            'profile_chunk_id' => $chunk->profile_chunk_id,
            'status' => MaterialProfileStepStatus::QUEUED,
            'workflow_token' => $workflowToken,
            'step_queued_at' => $isFirst ? now() : null,
        ]);
    }
}
