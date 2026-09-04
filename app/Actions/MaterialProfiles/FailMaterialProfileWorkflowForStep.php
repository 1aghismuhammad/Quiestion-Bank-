<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileStep;
use Illuminate\Support\Facades\DB;

/**
 * Terminal-fails a workflow on behalf of a specific Step delivery.
 *
 * The token guards make an obsolete delivery a no-op, so a redelivered or
 * timed-out Job can never fail a newer workflow or reopen a terminal one.
 */
class FailMaterialProfileWorkflowForStep
{
    use LocksMaterialProfileWorkflow;

    public function __construct(
        private FinalizeMaterialProfileFailure $finalizeFailure,
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
    ) {}

    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
        MaterialProfileErrorCode $errorCode,
    ): bool {
        return DB::transaction(function () use (
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
            $errorCode,
        ): bool {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);

            if ($version->status->isTerminal()) {
                return false;
            }

            $step = $steps->first(
                fn (MaterialProfileStep $candidate): bool => (int) $candidate->profile_step_id === $profileStepId,
            );

            try {
                $this->assertAuthority->assertJobFailureAuthority(
                    $version,
                    $workflowToken,
                    $step,
                    $stepExecutionToken,
                );
            } catch (MaterialProfileRejectedException) {
                return false;
            }

            $this->finalizeFailure->apply($version, $steps, $errorCode, $profileStepId);

            return true;
        });
    }
}
