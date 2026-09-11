<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptErrorCode;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use Illuminate\Support\Facades\DB;

class FailBlueprintAttempt
{
    use LocksQuestionBlueprintWorkflow;

    public function handle(
        int $blueprintId,
        string $workflowToken,
        string $stepExecutionToken,
        int $attemptId,
        BlueprintAttemptErrorCode $attemptError,
        ?BlueprintProviderAttemptMetadata $metadata = null,
    ): bool {
        return DB::transaction(function () use (
            $blueprintId,
            $workflowToken,
            $stepExecutionToken,
            $attemptId,
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

            if ($locked->lifecycle_status !== BlueprintLifecycleStatus::Draft
                || $locked->ai_fill_status !== BlueprintAiFillStatus::Processing
                || (string) $locked->workflow_token !== $workflowToken
                || (string) $locked->step_execution_token !== $stepExecutionToken) {
                return false;
            }

            $attempt = QuestionBlueprintAttempt::query()
                ->whereKey($attemptId)
                ->where('blueprint_id', $locked->blueprint_id)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status !== BlueprintAttemptStatus::Started) {
                return false;
            }

            $attempt->status = BlueprintAttemptStatus::Failed;
            $attempt->error_code = $attemptError->value;
            $attempt->input_tokens = $metadata?->inputTokens;
            $attempt->output_tokens = $metadata?->outputTokens;
            $attempt->total_tokens = $metadata?->totalTokens;
            $attempt->latency_ms = $metadata?->latencyMs;
            $attempt->finished_at = now();
            $attempt->save();

            $now = now();
            $locked->heartbeat_at = $now;
            $locked->lease_expires_at = $now->clone()->addSeconds(
                (int) config('question_blueprint.processing_lease_seconds', 120),
            );
            $locked->save();

            return true;
        });
    }
}
