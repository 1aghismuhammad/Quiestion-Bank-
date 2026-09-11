<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintRowOrigin;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use Illuminate\Support\Facades\DB;

class PersistBlueprintAiFillSuccess
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(
        private AssertReadyMatchingProfile $assertProfile,
        private PersistBlueprintRows $persistRows,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function handle(
        int $blueprintId,
        string $workflowToken,
        string $stepExecutionToken,
        int $attemptId,
        array $rows,
        BlueprintProviderAttemptMetadata $metadata,
    ): bool {
        return DB::transaction(function () use (
            $blueprintId,
            $workflowToken,
            $stepExecutionToken,
            $attemptId,
            $rows,
            $metadata,
        ): bool {
            $blueprint = QuestionBlueprint::query()->whereKey($blueprintId)->first();

            if ($blueprint === null) {
                return false;
            }

            $material = $this->lockUserAndMaterial((int) $blueprint->user_id, (int) $blueprint->material_id);

            if ($blueprint->profile_version_id !== null) {
                $this->lockProfileVersion((int) $blueprint->profile_version_id);
            }

            $this->lockSeries((int) $blueprint->blueprint_series_id);
            $locked = $this->lockBlueprint($blueprintId);
            $this->lockRowsAscending($blueprintId);

            if ($locked->lifecycle_status !== BlueprintLifecycleStatus::Draft
                || $locked->ai_fill_status !== BlueprintAiFillStatus::Processing
                || (string) $locked->workflow_token !== $workflowToken
                || (string) $locked->step_execution_token !== $stepExecutionToken) {
                return false;
            }

            $this->assertProfile->requireReferencedReady($material, (int) $locked->profile_version_id);

            $attempt = QuestionBlueprintAttempt::query()
                ->whereKey($attemptId)
                ->where('blueprint_id', $locked->blueprint_id)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status !== BlueprintAttemptStatus::Started) {
                return false;
            }

            $this->persistRows->replace($locked, $rows, BlueprintRowOrigin::Suggested);

            $attempt->status = BlueprintAttemptStatus::Succeeded;
            $attempt->input_tokens = $metadata->inputTokens;
            $attempt->output_tokens = $metadata->outputTokens;
            $attempt->total_tokens = $metadata->totalTokens;
            $attempt->latency_ms = $metadata->latencyMs;
            $attempt->error_code = null;
            $attempt->finished_at = now();
            $attempt->save();

            $locked->ai_fill_status = BlueprintAiFillStatus::Succeeded;
            $locked->lifecycle_status = BlueprintLifecycleStatus::Draft;
            $locked->error_code = null;
            $locked->error_message = null;
            $locked->workflow_token = null;
            $locked->step_execution_token = null;
            $locked->heartbeat_at = null;
            $locked->lease_expires_at = null;
            $locked->save();

            return true;
        });
    }
}
