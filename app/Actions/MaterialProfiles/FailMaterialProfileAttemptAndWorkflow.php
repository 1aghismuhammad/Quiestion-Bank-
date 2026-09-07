<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\ProfileProviderAttemptMetadata;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileStep;
use Illuminate\Support\Facades\DB;

/**
 * Atomically closes a started Attempt and terminal-fails its workflow.
 *
 * There is no committed window in which the Attempt is failed while the same
 * Step and Version remain processing. Lost authority writes nothing.
 */
class FailMaterialProfileAttemptAndWorkflow
{
    use LocksMaterialProfileWorkflow;
    use PersistsMaterialProfileAttempts;

    public function __construct(
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
        private FinalizeMaterialProfileFailure $finalizeFailure,
    ) {}

    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
        int $attemptId,
        MaterialProfileAttemptErrorCode $attemptErrorCode,
        MaterialProfileErrorCode $workflowErrorCode,
        ?ProfileProviderAttemptMetadata $metadata = null,
    ): bool {
        return DB::transaction(function () use (
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
            $attemptId,
            $attemptErrorCode,
            $workflowErrorCode,
            $metadata,
        ): bool {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);
            $attempts = $this->lockAttemptsAscending($profileVersionId);

            if ((int) $version->profile_version_id !== $profileVersionId) {
                return false;
            }

            $step = $steps->first(
                fn (MaterialProfileStep $candidate): bool => (int) $candidate->profile_step_id === $profileStepId,
            );

            if ($step === null
                || (int) $step->profile_version_id !== (int) $version->profile_version_id
                || (int) $step->profile_step_id !== $profileStepId) {
                return false;
            }

            try {
                $this->assertAuthority->handle($version, $workflowToken, $step, $stepExecutionToken);
            } catch (MaterialProfileRejectedException) {
                return false;
            }

            $attempt = $attempts->first(
                fn (MaterialProfileAttempt $candidate): bool => (int) $candidate->profile_attempt_id === $attemptId
                    && (int) $candidate->profile_step_id === (int) $step->profile_step_id
                    && (int) $candidate->profile_version_id === (int) $version->profile_version_id,
            );

            if ($attempt === null || $attempt->status !== MaterialProfileAttemptStatus::STARTED) {
                return false;
            }

            $this->applyAttemptOutcome($attempt, MaterialProfileAttemptStatus::FAILED, $metadata, $attemptErrorCode);
            $this->finalizeFailure->apply($version, $steps, $workflowErrorCode, $profileStepId);

            return true;
        });
    }
}
