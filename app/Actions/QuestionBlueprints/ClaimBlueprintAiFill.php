<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintClaimResult;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintClaimOutcome;
use App\Enums\BlueprintLifecycleStatus;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use Illuminate\Support\Facades\DB;

class ClaimBlueprintAiFill
{
    use LocksQuestionBlueprintWorkflow;

    public function handle(
        int $blueprintId,
        string $workflowToken,
        string $stepExecutionToken,
    ): BlueprintClaimResult {
        return DB::transaction(function () use ($blueprintId, $workflowToken, $stepExecutionToken): BlueprintClaimResult {
            $blueprint = QuestionBlueprint::query()->whereKey($blueprintId)->first();

            if ($blueprint === null) {
                return BlueprintClaimResult::of(BlueprintClaimOutcome::Terminal);
            }

            $this->lockUserAndMaterial((int) $blueprint->user_id, (int) $blueprint->material_id);

            if ($blueprint->profile_version_id !== null) {
                $this->lockProfileVersion((int) $blueprint->profile_version_id);
            }

            $this->lockSeries((int) $blueprint->blueprint_series_id);
            $locked = $this->lockBlueprint($blueprintId);
            $this->lockRowsAscending($blueprintId);

            if ($locked->lifecycle_status !== BlueprintLifecycleStatus::Draft) {
                return BlueprintClaimResult::of(BlueprintClaimOutcome::Terminal);
            }

            if ((string) $locked->workflow_token !== $workflowToken) {
                return BlueprintClaimResult::of(BlueprintClaimOutcome::Revoked);
            }

            if ($stepExecutionToken === '') {
                return BlueprintClaimResult::of(BlueprintClaimOutcome::Revoked);
            }

            if ($locked->ai_fill_status->isTerminal()) {
                return BlueprintClaimResult::of(BlueprintClaimOutcome::Terminal);
            }

            $now = now();
            $leaseSeconds = (int) config('question_blueprint.processing_lease_seconds', 120);

            if ($locked->ai_fill_status === BlueprintAiFillStatus::Queued) {
                $stored = $locked->step_execution_token;

                if (is_string($stored) && $stored !== '' && $stored !== $stepExecutionToken) {
                    return BlueprintClaimResult::of(BlueprintClaimOutcome::Duplicate);
                }

                $locked->ai_fill_status = BlueprintAiFillStatus::Processing;
                $locked->step_execution_token = $stepExecutionToken;
                $locked->claimed_at ??= $now;
                $locked->heartbeat_at = $now;
                $locked->lease_expires_at = $now->clone()->addSeconds($leaseSeconds);
                $locked->save();

                return BlueprintClaimResult::of(BlueprintClaimOutcome::Claimed);
            }

            if ($locked->ai_fill_status === BlueprintAiFillStatus::Processing) {
                if ((string) $locked->step_execution_token !== $stepExecutionToken) {
                    return BlueprintClaimResult::of(BlueprintClaimOutcome::Duplicate);
                }

                if ($locked->lease_expires_at === null || $locked->lease_expires_at->lte($now)) {
                    return BlueprintClaimResult::of(BlueprintClaimOutcome::Expired);
                }

                $started = QuestionBlueprintAttempt::query()
                    ->where('blueprint_id', $locked->blueprint_id)
                    ->where('status', BlueprintAttemptStatus::Started)
                    ->lockForUpdate()
                    ->exists();

                if ($started) {
                    return BlueprintClaimResult::of(BlueprintClaimOutcome::Duplicate);
                }

                $locked->heartbeat_at = $now;
                $locked->lease_expires_at = $now->clone()->addSeconds($leaseSeconds);
                $locked->save();

                return BlueprintClaimResult::of(BlueprintClaimOutcome::Resumed);
            }

            return BlueprintClaimResult::of(BlueprintClaimOutcome::Terminal);
        });
    }
}
