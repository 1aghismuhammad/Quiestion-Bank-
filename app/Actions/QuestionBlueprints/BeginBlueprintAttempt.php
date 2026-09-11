<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Exceptions\QuestionBlueprints\BlueprintAttemptBudgetExhaustedException;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use Illuminate\Support\Facades\DB;

class BeginBlueprintAttempt
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(private AssertReadyMatchingProfile $assertProfile) {}

    public function handle(
        int $blueprintId,
        string $workflowToken,
        string $stepExecutionToken,
        string $provider,
        string $model,
        string $promptVersion,
    ): ?QuestionBlueprintAttempt {
        return DB::transaction(function () use (
            $blueprintId,
            $workflowToken,
            $stepExecutionToken,
            $provider,
            $model,
            $promptVersion,
        ): ?QuestionBlueprintAttempt {
            $blueprint = QuestionBlueprint::query()->whereKey($blueprintId)->first();

            if ($blueprint === null) {
                return null;
            }

            $material = $this->lockUserAndMaterial((int) $blueprint->user_id, (int) $blueprint->material_id);

            if ($blueprint->profile_version_id !== null) {
                $this->lockProfileVersion((int) $blueprint->profile_version_id);
            }

            $this->lockSeries((int) $blueprint->blueprint_series_id);
            $locked = $this->lockBlueprint($blueprintId);
            $this->lockRowsAscending($blueprintId);

            if (! $this->ownsFill($locked, $workflowToken, $stepExecutionToken)) {
                return null;
            }

            $this->assertProfile->requireReferencedReady($material, (int) $locked->profile_version_id);

            $started = QuestionBlueprintAttempt::query()
                ->where('blueprint_id', $locked->blueprint_id)
                ->where('status', BlueprintAttemptStatus::Started)
                ->lockForUpdate()
                ->exists();

            if ($started) {
                return null;
            }

            $maxAttempts = max(1, (int) config('question_blueprint.max_provider_attempts', 3));
            $thisWorkflow = QuestionBlueprintAttempt::query()
                ->where('blueprint_id', $locked->blueprint_id)
                ->when(
                    $locked->queued_at !== null,
                    fn ($query) => $query->where('started_at', '>=', $locked->queued_at),
                )
                ->count();

            if ($thisWorkflow >= $maxAttempts) {
                throw new BlueprintAttemptBudgetExhaustedException((int) $locked->blueprint_id);
            }

            $lastNumber = (int) QuestionBlueprintAttempt::query()
                ->where('blueprint_id', $locked->blueprint_id)
                ->max('attempt_number');

            $now = now();
            $leaseSeconds = (int) config('question_blueprint.processing_lease_seconds', 120);
            $locked->heartbeat_at = $now;
            $locked->lease_expires_at = $now->clone()->addSeconds($leaseSeconds);
            $locked->save();

            return QuestionBlueprintAttempt::query()->create([
                'blueprint_id' => $locked->blueprint_id,
                'attempt_number' => $lastNumber + 1,
                'provider' => $provider,
                'model' => $model,
                'prompt_version' => $promptVersion,
                'status' => BlueprintAttemptStatus::Started,
                'started_at' => $now,
            ]);
        });
    }

    private function ownsFill(
        QuestionBlueprint $blueprint,
        string $workflowToken,
        string $stepExecutionToken,
    ): bool {
        return $blueprint->lifecycle_status === BlueprintLifecycleStatus::Draft
            && $blueprint->ai_fill_status === BlueprintAiFillStatus::Processing
            && (string) $blueprint->workflow_token === $workflowToken
            && (string) $blueprint->step_execution_token === $stepExecutionToken
            && $blueprint->lease_expires_at !== null
            && $blueprint->lease_expires_at->gt(now());
    }
}
