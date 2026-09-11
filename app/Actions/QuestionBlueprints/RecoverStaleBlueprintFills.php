<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptErrorCode;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintErrorCode;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverStaleBlueprintFills
{
    use LocksQuestionBlueprintWorkflow;

    public function handle(): int
    {
        $recovered = 0;

        foreach ($this->candidateIds() as $blueprintId) {
            if ($this->recoverOne((int) $blueprintId)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function recoverOne(int $blueprintId): bool
    {
        return DB::transaction(function () use ($blueprintId): bool {
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

            if (! $locked->ai_fill_status->isInFlight()) {
                return false;
            }

            $now = Carbon::now();
            $queuedCutoff = $now->copy()->subSeconds((int) config('question_blueprint.queued_abandonment_seconds', 900));

            $staleProcessing = $locked->ai_fill_status === BlueprintAiFillStatus::Processing
                && ($locked->lease_expires_at === null || $locked->lease_expires_at->lte($now));

            $staleQueued = $locked->ai_fill_status === BlueprintAiFillStatus::Queued
                && $locked->queued_at !== null
                && $locked->queued_at->lte($queuedCutoff);

            if (! $staleProcessing && ! $staleQueued) {
                return false;
            }

            QuestionBlueprintAttempt::query()
                ->where('blueprint_id', $locked->blueprint_id)
                ->where('status', BlueprintAttemptStatus::Started)
                ->lockForUpdate()
                ->get()
                ->each(function (QuestionBlueprintAttempt $attempt): void {
                    $attempt->status = BlueprintAttemptStatus::Failed;
                    $attempt->error_code = BlueprintAttemptErrorCode::ProviderHttp->value;
                    $attempt->finished_at = now();
                    $attempt->save();
                });

            $code = $staleQueued ? BlueprintErrorCode::QueuedAbandoned : BlueprintErrorCode::StaleRecovery;
            $locked->ai_fill_status = BlueprintAiFillStatus::Failed;
            $locked->error_code = $code->value;
            $locked->error_message = $code->userMessage();
            $locked->workflow_token = null;
            $locked->step_execution_token = null;
            $locked->heartbeat_at = null;
            $locked->lease_expires_at = null;
            $locked->save();

            return true;
        });
    }

    /**
     * @return list<int>
     */
    private function candidateIds(): array
    {
        $batch = max(1, (int) config('question_blueprint.stale_recovery_batch_size', 50));
        $queuedCutoff = now()->subSeconds((int) config('question_blueprint.queued_abandonment_seconds', 900));

        $processing = QuestionBlueprint::query()
            ->where('ai_fill_status', BlueprintAiFillStatus::Processing)
            ->where(function ($query): void {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->orderBy('blueprint_id')
            ->limit($batch)
            ->pluck('blueprint_id');

        $queued = QuestionBlueprint::query()
            ->where('ai_fill_status', BlueprintAiFillStatus::Queued)
            ->where('queued_at', '<=', $queuedCutoff)
            ->orderBy('blueprint_id')
            ->limit($batch)
            ->pluck('blueprint_id');

        return $processing->merge($queued)->unique()->sort()->take($batch)->values()->all();
    }
}
