<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStepPurpose;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileStep;
use App\Services\AI\MaterialProfilePromptBuilder;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Support\Facades\DB;

/**
 * Copies the minimum request data into memory under the canonical locks, then
 * releases them so the provider call happens outside every transaction.
 */
class BuildMaterialProfileMapRequest
{
    use LocksMaterialProfileWorkflow;
    use VerifiesMaterialProfileContext;

    public function __construct(
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
        private MaterialContentHasher $hasher,
        private MaterialProfilePromptBuilder $promptBuilder,
    ) {}

    /**
     * @return ProfileMapRequest|null Null when execution authority has been lost.
     *
     * @throws MaterialProfileRejectedException When the persisted workflow context is invalid.
     */
    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
    ): ?ProfileMapRequest {
        return DB::transaction(function () use (
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
        ): ?ProfileMapRequest {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $chunks = $this->lockChunksAscending($profileVersionId);

            $step = $steps->first(
                fn (MaterialProfileStep $candidate): bool => (int) $candidate->profile_step_id === $profileStepId,
            );

            if ($step === null) {
                return null;
            }

            try {
                $this->assertAuthority->handle($version, $workflowToken, $step, $stepExecutionToken);
            } catch (MaterialProfileRejectedException) {
                return null;
            }

            if ($step->purpose !== MaterialProfileStepPurpose::MAP || $step->profile_chunk_id === null) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $material = $this->lockedMaterialFor($version);
            $content = $this->assertMaterialFingerprint($version, $material, $this->hasher);

            $chunk = $chunks->first(
                fn (MaterialProfileChunk $candidate): bool => (int) $candidate->profile_chunk_id === (int) $step->profile_chunk_id,
            );

            if ($chunk === null || (int) $chunk->chunk_index !== (int) $step->step_index) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $core = $this->assertChunkIdentity($version, $chunk, $content, $this->hasher);

            return new ProfileMapRequest(
                profileVersionId: (int) $version->profile_version_id,
                chunkIndex: (int) $chunk->chunk_index,
                coreText: $core,
                overlapText: $this->precedingOverlapText($chunk, $content),
                coreCharStart: (int) $chunk->char_start,
                coreCharEnd: (int) $chunk->char_end,
                model: $this->model(),
                promptVersion: $this->promptBuilder->mapVersion(),
            );
        });
    }

    private function model(): string
    {
        $model = config('material_profile.primary_model');

        if (! is_string($model) || $model === '') {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        return $model;
    }
}
