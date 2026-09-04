<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileClaimResult;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ClaimMaterialProfileStep
{
    use LocksMaterialProfileWorkflow;

    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
    ): MaterialProfileClaimResult {
        return DB::transaction(function () use ($profileVersionId, $profileStepId, $workflowToken, $stepExecutionToken): MaterialProfileClaimResult {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);

            $step = $steps->first(
                fn (MaterialProfileStep $candidate): bool => (int) $candidate->profile_step_id === $profileStepId,
            );

            if ($step === null) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Terminal);
            }

            if ($version->status->isTerminal()) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Terminal);
            }

            if ((string) $version->workflow_token !== $workflowToken
                || (string) $step->workflow_token !== $workflowToken) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Revoked);
            }

            if ($step->status->isTerminal()) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Terminal);
            }

            $expected = $this->expectedNextStep($steps);

            if ($expected === null || (int) $expected->profile_step_id !== (int) $step->profile_step_id) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::NotNextStep);
            }

            if ($step->purpose === MaterialProfileStepPurpose::MAP && $step->profile_chunk_id === null) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Terminal);
            }

            if ($step->purpose === MaterialProfileStepPurpose::REDUCE && $step->profile_chunk_id !== null) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Terminal);
            }

            if ($step->status === MaterialProfileStepStatus::QUEUED) {
                $this->markClaimed($version, $step, $stepExecutionToken);

                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Claimed);
            }

            if ($step->status === MaterialProfileStepStatus::PROCESSING) {
                if ((string) $step->step_execution_token !== $stepExecutionToken) {
                    return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Duplicate);
                }

                if ($this->leaseExpired($step)) {
                    return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Expired);
                }

                $this->touchLease($step);

                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Resumed);
            }

            return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Terminal);
        });
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function expectedNextStep(Collection $steps): ?MaterialProfileStep
    {
        $maps = $steps
            ->filter(fn (MaterialProfileStep $step): bool => $step->purpose === MaterialProfileStepPurpose::MAP)
            ->sortBy(fn (MaterialProfileStep $step): int => (int) $step->step_index)
            ->values();

        foreach ($maps as $map) {
            if ($map->status === MaterialProfileStepStatus::FAILED) {
                return null;
            }

            if ($map->status !== MaterialProfileStepStatus::READY) {
                return $map;
            }
        }

        $reduce = $steps->first(
            fn (MaterialProfileStep $step): bool => $step->purpose === MaterialProfileStepPurpose::REDUCE,
        );

        if ($reduce !== null && $reduce->status !== MaterialProfileStepStatus::READY) {
            return $reduce;
        }

        return null;
    }

    private function markClaimed(
        MaterialProfileVersion $version,
        MaterialProfileStep $step,
        string $stepExecutionToken,
    ): void {
        $now = now();
        $leaseSeconds = (int) config('material_profile.processing_lease_seconds');

        if ($version->status === MaterialProfileStatus::QUEUED) {
            $version->status = MaterialProfileStatus::PROCESSING;
            $version->started_at ??= $now;
            $version->save();
        }

        $step->status = MaterialProfileStepStatus::PROCESSING;
        $step->step_execution_token = $stepExecutionToken;
        $step->claimed_at ??= $now;
        $step->step_queued_at ??= $now;
        $step->heartbeat_at = $now;
        $step->lease_expires_at = $now->clone()->addSeconds($leaseSeconds);
        $step->save();
    }

    private function touchLease(MaterialProfileStep $step): void
    {
        $now = now();
        $leaseSeconds = (int) config('material_profile.processing_lease_seconds');
        $step->heartbeat_at = $now;
        $step->lease_expires_at = $now->clone()->addSeconds($leaseSeconds);
        $step->save();
    }

    private function leaseExpired(MaterialProfileStep $step): bool
    {
        return $step->lease_expires_at !== null && $step->lease_expires_at->lte(now());
    }
}
