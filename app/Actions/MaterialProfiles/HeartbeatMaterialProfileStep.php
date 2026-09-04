<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileClaimResult;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Models\MaterialProfileStep;
use Illuminate\Support\Facades\DB;

class HeartbeatMaterialProfileStep
{
    use LocksMaterialProfileWorkflow;

    public function __construct(private AssertMaterialProfileWorkflowAuthority $assertAuthority) {}

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

            if ($step === null || $version->status->isTerminal() || $step->status->isTerminal()) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Terminal);
            }

            if ((string) $version->workflow_token !== $workflowToken
                || (string) $step->workflow_token !== $workflowToken) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Revoked);
            }

            if ($version->status !== MaterialProfileStatus::PROCESSING
                || $step->status !== MaterialProfileStepStatus::PROCESSING) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Terminal);
            }

            if ($stepExecutionToken === '') {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Revoked);
            }

            if ((string) $step->step_execution_token !== $stepExecutionToken) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Duplicate);
            }

            if (! $this->assertAuthority->hasLiveProcessingLease($step)) {
                return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Expired);
            }

            $now = now();
            $leaseSeconds = (int) config('material_profile.processing_lease_seconds');
            $step->heartbeat_at = $now;
            $step->lease_expires_at = $now->clone()->addSeconds($leaseSeconds);
            $step->save();

            return MaterialProfileClaimResult::of(MaterialProfileClaimOutcome::Resumed);
        });
    }
}
