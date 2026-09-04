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

        if ($step->lease_expires_at === null || ! $step->lease_expires_at->gt(now())) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::Revoked);
        }
    }
}
