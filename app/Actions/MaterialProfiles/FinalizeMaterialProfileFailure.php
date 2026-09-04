<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FinalizeMaterialProfileFailure
{
    use LocksMaterialProfileWorkflow;

    public function handle(int $profileVersionId, MaterialProfileErrorCode $errorCode): MaterialProfileVersion
    {
        return DB::transaction(function () use ($profileVersionId, $errorCode): MaterialProfileVersion {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);

            return $this->apply($version, $steps, $errorCode);
        });
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    public function apply(
        MaterialProfileVersion $version,
        Collection $steps,
        MaterialProfileErrorCode $errorCode,
        ?int $targetStepId = null,
    ): MaterialProfileVersion {
        if ($version->status === MaterialProfileStatus::FAILED) {
            return $version;
        }

        if ($version->status === MaterialProfileStatus::READY) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $now = now();
        $version->status = MaterialProfileStatus::FAILED;
        $version->failed_at = $now;
        $version->completed_at = null;
        $version->error_code = $errorCode->value;
        $version->error_message = $errorCode->userMessage();
        $version->save();

        foreach ($steps as $step) {
            if ($step->status->isTerminal()) {
                continue;
            }

            $isTarget = $targetStepId !== null
                && (int) $step->profile_step_id === $targetStepId;
            $stepCode = $isTarget
                ? $errorCode
                : ($targetStepId === null && $step->status === MaterialProfileStepStatus::PROCESSING
                    ? $errorCode
                    : MaterialProfileErrorCode::StepAborted);

            $step->status = MaterialProfileStepStatus::FAILED;
            $step->error_code = $stepCode->value;
            $step->error_message = $stepCode->userMessage();
            $step->lease_expires_at = null;
            $step->save();
        }

        return $version->refresh();
    }
}
