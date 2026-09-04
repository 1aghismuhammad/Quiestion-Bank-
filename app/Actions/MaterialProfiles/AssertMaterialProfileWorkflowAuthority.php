<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;

class AssertMaterialProfileWorkflowAuthority
{
    public function handle(
        MaterialProfileVersion $version,
        string $workflowToken,
        ?MaterialProfileStep $step = null,
        ?string $stepExecutionToken = null,
    ): void {
        if ($version->status !== MaterialProfileStatus::PROCESSING) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ((string) $version->workflow_token !== $workflowToken) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ($step === null) {
            return;
        }

        if ((int) $step->profile_version_id !== (int) $version->profile_version_id) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if ((string) $step->workflow_token !== (string) $version->workflow_token
            || (string) $step->workflow_token !== $workflowToken) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ($step->status !== MaterialProfileStepStatus::PROCESSING) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if (! is_string($stepExecutionToken) || $stepExecutionToken === '') {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ((string) $step->step_execution_token !== $stepExecutionToken) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if (! $this->hasLiveProcessingLease($step)) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }
    }

    /**
     * A processing Step may be resumed, heartbeated, or failed by a Job only
     * while its lease exists and is strictly later than now. A null lease is
     * never treated as a live lease.
     */
    public function hasLiveProcessingLease(MaterialProfileStep $step): bool
    {
        return $step->lease_expires_at !== null && $step->lease_expires_at->gt(now());
    }

    /**
     * Write authority for a Job `failed()` callback.
     *
     * A processing delivery may terminal-fail the workflow only while the Step
     * lease is strictly unexpired. An expired processing Step has no write
     * authority; recovery assigns `stale_recovery` later.
     *
     * A queued delivery that fails before claim may terminal-fail only while
     * both the Version and the Step are still queued, the stored Step token
     * matches, and the Step has never been claimed.
     */
    public function assertJobFailureAuthority(
        MaterialProfileVersion $version,
        string $workflowToken,
        ?MaterialProfileStep $step,
        ?string $stepExecutionToken,
    ): void {
        if ($step === null || $step->status->isTerminal()) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ($step->status === MaterialProfileStepStatus::QUEUED) {
            $this->assertQueuedDeliveryFailureAuthority($version, $workflowToken, $step, $stepExecutionToken);

            return;
        }

        $this->handle($version, $workflowToken, $step, $stepExecutionToken);
    }

    private function assertQueuedDeliveryFailureAuthority(
        MaterialProfileVersion $version,
        string $workflowToken,
        MaterialProfileStep $step,
        ?string $stepExecutionToken,
    ): void {
        if ($version->status !== MaterialProfileStatus::QUEUED) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ((string) $version->workflow_token !== $workflowToken) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ((int) $step->profile_version_id !== (int) $version->profile_version_id) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if ((string) $step->workflow_token !== $workflowToken) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ($step->status !== MaterialProfileStepStatus::QUEUED || $step->claimed_at !== null) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if (! is_string($stepExecutionToken) || $stepExecutionToken === '') {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }

        if ((string) $step->step_execution_token !== $stepExecutionToken) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }
    }
}
