<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptErrorCode;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use Illuminate\Support\Facades\DB;

class FailBlueprintAttemptAndWorkflow
{
    use LocksQuestionBlueprintWorkflow;

    public function handle(
        int $blueprintId,
        string $workflowToken,
        string $stepExecutionToken,
        ?int $attemptId,
        BlueprintErrorCode $workflowError,
        ?BlueprintAttemptErrorCode $attemptError = null,
        ?BlueprintProviderAttemptMetadata $metadata = null,
    ): bool {
        return DB::transaction(function () use (
            $blueprintId,
            $workflowToken,
            $stepExecutionToken,
            $attemptId,
            $workflowError,
            $attemptError,
            $metadata,
        ): bool {
            $blueprint = QuestionBlueprint::query()->whereKey($blueprintId)->first();

            if ($blueprint === null) {
                return false;
            }

            $this->lockUserAndMaterial((int) $blueprint->user_id, (int) $blueprint->material_id);

            if ($blueprint->profile_version_id !== null) {
                $this->lockProfileVersion((int) $blueprint->profile_version_id);
            }

            $this->lockSeries((int) $blueprint->blueprint_series_id);
            $locked = $this->lockBlueprint($blueprintId);
            $this->lockRowsAscending($blueprintId);

            if ($locked->lifecycle_status !== BlueprintLifecycleStatus::Draft
                || ! $locked->ai_fill_status->isInFlight()
                || (string) $locked->workflow_token !== $workflowToken
                || (string) $locked->step_execution_token !== $stepExecutionToken) {
                return false;
            }

            if ($locked->ai_fill_status === BlueprintAiFillStatus::Processing
                && ($locked->lease_expires_at === null || $locked->lease_expires_at->lte(now()))) {
                return false;
            }

            $attempts = QuestionBlueprintAttempt::query()
                ->where('blueprint_id', $locked->blueprint_id)
                ->where('status', BlueprintAttemptStatus::Started)
                ->lockForUpdate()
                ->get();

            foreach ($attempts as $attempt) {
                $applyMetadata = $attemptId === null
                    || (int) $attempt->blueprint_attempt_id === $attemptId;

                $attempt->status = BlueprintAttemptStatus::Failed;
                $attempt->error_code = $attemptError?->value;
                $attempt->finished_at = now();

                if ($applyMetadata && $metadata !== null) {
                    $attempt->input_tokens = $metadata->inputTokens;
                    $attempt->output_tokens = $metadata->outputTokens;
                    $attempt->total_tokens = $metadata->totalTokens;
                    $attempt->latency_ms = $metadata->latencyMs;
                }

                $attempt->save();
            }

            $locked->ai_fill_status = BlueprintAiFillStatus::Failed;
            $locked->lifecycle_status = BlueprintLifecycleStatus::Draft;
            $locked->error_code = $workflowError->value;
            $locked->error_message = $workflowError->userMessage();
            $locked->workflow_token = null;
            $locked->step_execution_token = null;
            $locked->heartbeat_at = null;
            $locked->lease_expires_at = null;
            $locked->save();

            return true;
        });
    }
}
