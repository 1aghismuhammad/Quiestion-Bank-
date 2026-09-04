<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\ProfileProviderAttemptMetadata;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileStep;
use Illuminate\Support\Facades\DB;

/**
 * Closes a started Attempt as failed, but only while the delivery still holds
 * execution authority. A late worker persists nothing.
 */
class FailMaterialProfileAttempt
{
    use LocksMaterialProfileWorkflow;
    use PersistsMaterialProfileAttempts;

    public function __construct(private AssertMaterialProfileWorkflowAuthority $assertAuthority) {}

    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
        int $attemptId,
        MaterialProfileAttemptErrorCode $errorCode,
        ?ProfileProviderAttemptMetadata $metadata = null,
    ): bool {
        return DB::transaction(function () use (
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
            $attemptId,
            $errorCode,
            $metadata,
        ): bool {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);

            $step = $steps->first(
                fn (MaterialProfileStep $candidate): bool => (int) $candidate->profile_step_id === $profileStepId,
            );

            if ($step === null) {
                return false;
            }

            try {
                $this->assertAuthority->handle($version, $workflowToken, $step, $stepExecutionToken);
            } catch (MaterialProfileRejectedException) {
                return false;
            }

            $attempt = MaterialProfileAttempt::query()
                ->whereKey($attemptId)
                ->where('profile_step_id', $step->profile_step_id)
                ->where('profile_version_id', $version->profile_version_id)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status !== MaterialProfileAttemptStatus::STARTED) {
                return false;
            }

            $this->applyAttemptOutcome($attempt, MaterialProfileAttemptStatus::FAILED, $metadata, $errorCode);
            $this->refreshStepLease($step);

            return true;
        });
    }
}
