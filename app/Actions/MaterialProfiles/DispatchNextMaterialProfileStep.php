<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileStepDispatch;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Jobs\ReduceMaterialProfileJob;
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
