<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileStepDispatch;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place that selects and dispatches the next Material Profile Step.
 *
 * Token authority lives in the database: a queued Step keeps whichever
 * step_execution_token was minted for it, so repeated invocation redelivers the
 * stored token instead of creating a competing one.
 */
class DispatchNextMaterialProfileStep
{
    use LocksMaterialProfileWorkflow;
    use ResolvesNextMaterialProfileStep;

    public function handle(int $profileVersionId): ?MaterialProfileStepDispatch
    {
        $dispatch = DB::transaction(function () use ($profileVersionId): ?MaterialProfileStepDispatch {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);

            return $this->prepareLocked($version, $steps);
        });

        if ($dispatch !== null) {
            $this->push($dispatch);
        }

        return $dispatch;
    }

    /**
     * Claim the next queued Step for dispatch while the caller holds the
     * canonical locks. Returns null when nothing may be dispatched, which
     * includes a terminal Version and a Step that another worker is processing.
     *
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    public function prepareLocked(
        MaterialProfileVersion $version,
        Collection $steps,
    ): ?MaterialProfileStepDispatch {
        if ($version->status->isTerminal()) {
            return null;
        }

        $next = $this->expectedNextStep($steps);

        if ($next === null || $next->status !== MaterialProfileStepStatus::QUEUED) {
            return null;
        }

        if ((string) $next->workflow_token !== (string) $version->workflow_token) {
            return null;
        }

        if ($next->purpose === MaterialProfileStepPurpose::REDUCE && ! $this->allMapStepsReady($steps)) {
            return null;
        }

        $stored = $next->step_execution_token;
        $token = is_string($stored) && $stored !== '' ? $stored : (string) Str::uuid();
        $dirty = false;

        if ((string) $next->step_execution_token !== $token) {
            $next->step_execution_token = $token;
            $dirty = true;
        }

        if ($next->step_queued_at === null) {
            $next->step_queued_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $next->save();
        }

        return new MaterialProfileStepDispatch(
            profileVersionId: (int) $version->profile_version_id,
            profileStepId: (int) $next->profile_step_id,
            workflowToken: (string) $version->workflow_token,
            stepExecutionToken: $token,
            purpose: $next->purpose,
        );
    }

    /**
     * Map success may commit only when the complete ordered topology is legal
     * and exactly one immediate next Step exists. Earlier non-ready maps are
     * never skipped, and a later ready map is never treated as already done.
     *
     * @param  Collection<int, MaterialProfileStep>  $steps
     * @param  Collection<int, MaterialProfileChunk>  $chunks
     *
     * @throws MaterialProfileRejectedException
     */
    public function assertMapSuccessHasExactlyOneNextStep(
        MaterialProfileVersion $version,
        Collection $steps,
        Collection $chunks,
        MaterialProfileStep $currentMap,
    ): MaterialProfileStep {
        if ($version->status !== MaterialProfileStatus::PROCESSING) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if ((string) $version->workflow_token !== (string) $currentMap->workflow_token
            || (int) $currentMap->profile_version_id !== (int) $version->profile_version_id
            || $currentMap->purpose !== MaterialProfileStepPurpose::MAP
            || $currentMap->status !== MaterialProfileStepStatus::PROCESSING
            || $currentMap->profile_chunk_id === null) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $this->assertEveryStepSharesVersionAndWorkflowToken($version, $steps);
        $this->assertNoOtherStepIsProcessing($steps, $currentMap);
        $this->assertMapChunkOneToOneIdentity($version, $steps, $chunks);
        $this->assertExactlyOneQueuedReduceStep($version, $steps);

        $maps = $this->orderedMapSteps($steps);
        $currentOccurrences = $maps->filter(
            fn (MaterialProfileStep $map): bool => (int) $map->profile_step_id === (int) $currentMap->profile_step_id,
        );

        if ($currentOccurrences->count() !== 1) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $expected = $this->expectedNextStep($steps);

        if ($expected === null
            || (int) $expected->profile_step_id !== (int) $currentMap->profile_step_id) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $position = $maps->search(
            fn (MaterialProfileStep $map): bool => (int) $map->profile_step_id === (int) $currentMap->profile_step_id,
        );

        if (! is_int($position)) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        foreach ($maps as $index => $map) {
            if ($index < $position && $map->status !== MaterialProfileStepStatus::READY) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            if ($index === $position && (
                $map->status !== MaterialProfileStepStatus::PROCESSING
                || (int) $map->profile_step_id !== (int) $currentMap->profile_step_id
            )) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            if ($index > $position && $map->status !== MaterialProfileStepStatus::QUEUED) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }
        }

        $next = $this->immediateNextStepAfterMap($maps, $position, $steps);

        if ($next === null
            || $next->status !== MaterialProfileStepStatus::QUEUED
            || (int) $next->profile_version_id !== (int) $version->profile_version_id
            || (string) $next->workflow_token !== (string) $version->workflow_token) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if ($next->purpose === MaterialProfileStepPurpose::MAP && $next->profile_chunk_id === null) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if ($next->purpose === MaterialProfileStepPurpose::REDUCE && $next->profile_chunk_id !== null) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        return $next;
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function assertNoOtherStepIsProcessing(Collection $steps, MaterialProfileStep $currentMap): void
    {
        $otherProcessing = $steps->first(
            fn (MaterialProfileStep $step): bool => $step->status === MaterialProfileStepStatus::PROCESSING
                && (int) $step->profile_step_id !== (int) $currentMap->profile_step_id,
        );

        if ($otherProcessing !== null) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     * @param  Collection<int, MaterialProfileChunk>  $chunks
     */
    private function assertMapChunkOneToOneIdentity(
        MaterialProfileVersion $version,
        Collection $steps,
        Collection $chunks,
    ): void {
        $maps = $this->orderedMapSteps($steps);
        $indexedChunks = $chunks
            ->sortBy(fn (MaterialProfileChunk $chunk): int => (int) $chunk->chunk_index)
            ->values();

        if ($maps->isEmpty() || $maps->count() !== $indexedChunks->count()) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $chunkIds = [];
        $stepIndexes = [];

        foreach ($maps as $index => $map) {
            if ($map->profile_chunk_id === null) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $chunk = $indexedChunks[$index] ?? null;
            $stepIndex = (int) $map->step_index;

            if ($chunk === null
                || (int) $chunk->profile_version_id !== (int) $version->profile_version_id
                || (int) $chunk->profile_chunk_id !== (int) $map->profile_chunk_id
                || (int) $chunk->chunk_index !== $stepIndex) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $chunkIds[] = (int) $map->profile_chunk_id;
            $stepIndexes[] = $stepIndex;
        }

        if (count($chunkIds) !== count(array_unique($chunkIds))
            || count($stepIndexes) !== count(array_unique($stepIndexes))) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function assertEveryStepSharesVersionAndWorkflowToken(
        MaterialProfileVersion $version,
        Collection $steps,
    ): void {
        foreach ($steps as $step) {
            if ((int) $step->profile_version_id !== (int) $version->profile_version_id
                || (string) $step->workflow_token !== (string) $version->workflow_token) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }
        }
    }

    /**
     * The sole reduce Step must exist, belong to this Version, carry the same
     * workflow token, have no Chunk, and remain queued until every map is ready.
     *
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function assertExactlyOneQueuedReduceStep(
        MaterialProfileVersion $version,
        Collection $steps,
    ): void {
        $reduces = $steps->filter(
            fn (MaterialProfileStep $step): bool => $step->purpose === MaterialProfileStepPurpose::REDUCE,
        );

        if ($reduces->count() !== 1) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $reduce = $reduces->first();

        if ($reduce === null
            || $reduce->profile_chunk_id !== null
            || $reduce->status !== MaterialProfileStepStatus::QUEUED
            || (int) $reduce->profile_version_id !== (int) $version->profile_version_id
            || (string) $reduce->workflow_token !== (string) $version->workflow_token) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    /**
     * The only legal successor is the map immediately after the current map by
     * step_index, or the sole reduce Step when the current map is the last map.
     *
     * @param  Collection<int, MaterialProfileStep>  $maps
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function immediateNextStepAfterMap(
        Collection $maps,
        int $currentPosition,
        Collection $steps,
    ): ?MaterialProfileStep {
        if ($currentPosition < ($maps->count() - 1)) {
            $next = $maps->get($currentPosition + 1);

            return $next instanceof MaterialProfileStep ? $next : null;
        }

        return $this->reduceStep($steps);
    }

    /**
     * Queue the delivery. Both Jobs are constructed with afterCommit(), so this
     * is safe to call either after the transaction closes or from within it.
     */
    public function push(MaterialProfileStepDispatch $dispatch): void
    {
        match ($dispatch->purpose) {
            MaterialProfileStepPurpose::MAP => AnalyzeMaterialProfileMapJob::dispatch(
                $dispatch->profileVersionId,
                $dispatch->profileStepId,
                $dispatch->workflowToken,
                $dispatch->stepExecutionToken,
            ),
            MaterialProfileStepPurpose::REDUCE => ReduceMaterialProfileJob::dispatch(
                $dispatch->profileVersionId,
                $dispatch->profileStepId,
                $dispatch->workflowToken,
                $dispatch->stepExecutionToken,
            ),
        };
    }
}
