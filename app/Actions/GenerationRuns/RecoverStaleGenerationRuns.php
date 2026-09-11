<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Support\Generations\ResolvesGenerationRunStaleCutoff;
use Illuminate\Support\Facades\DB;

class RecoverStaleGenerationRuns
{
    use LocksGenerationRun;
    use ResolvesGenerationRunStaleCutoff;

    public function __construct(
        private TerminalizeGenerationRun $terminalize,
        private DispatchQueuedRunChild $dispatchQueued,
    ) {}

    public function handle(): int
    {
        $recovered = 0;

        foreach ($this->candidateIds() as $runId) {
            if ($this->recoverOne((int) $runId)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function recoverOne(int $runId): bool
    {
        $dispatch = null;

        $acted = DB::transaction(function () use ($runId, &$dispatch): bool {
            $graph = $this->lockCanonicalRunGraph($runId);
            $run = $graph['run'];
            $children = $graph['children'];
            $usage = $graph['usage'];
            $items = $graph['items'];
            $attempts = $graph['attempts'];
            $cutoff = $this->generationRunStaleCutoff();

            if ($run->status->isTerminal()) {
                return false;
            }

            if ($usage->status !== UsageStatus::RESERVED) {
                return false;
            }

            $staleChild = $children->first(function (AiGeneration $child) use ($cutoff): bool {
                if ($child->generation_status === GenerationStatus::PROCESSING) {
                    return $child->updated_at !== null && $child->updated_at->lte($cutoff);
                }

                return false;
            });

            $hasLiveProcessing = $children->contains(function (AiGeneration $child) use ($cutoff): bool {
                if ($child->generation_status !== GenerationStatus::PROCESSING) {
                    return false;
                }

                return $child->updated_at === null || $child->updated_at->gt($cutoff);
            });

            $staleQueuedChild = $hasLiveProcessing
                ? null
                : $children->first(function (AiGeneration $child) use ($cutoff): bool {
                    return $child->generation_status === GenerationStatus::QUEUED
                        && $child->queued_at !== null
                        && $child->queued_at->lte($cutoff);
                });

            $staleQueuedRun = $run->status === GenerationRunStatus::Queued
                && $run->queued_at !== null
                && $run->queued_at->lte($cutoff)
                && $children->every(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::QUEUED);

            if ($staleChild !== null || $staleQueuedChild !== null || $staleQueuedRun) {
                $target = $staleChild ?? $staleQueuedChild ?? $children->first(
                    fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::QUEUED,
                );

                if ($target === null) {
                    return false;
                }

                $target->generation_status = GenerationStatus::FAILED;
                $target->error_code = GenerationErrorCode::StaleRecovery->value;
                $target->error_message = GenerationErrorCode::StaleRecovery->userMessage();
                $target->failed_at = now();
                $target->save();

                $this->terminalize->apply($run, $children, $usage, $items, $attempts);

                return true;
            }

            $dispatch = $this->dispatchQueued->prepareNextFromLockedGraph($graph);

            return $dispatch !== null;
        });

        $this->dispatchQueued->dispatch($dispatch);

        return $acted;
    }

    /**
     * @return list<int>
     */
    private function candidateIds(): array
    {
        $cutoff = $this->generationRunStaleCutoff();
        $batch = max(1, (int) config('generation.stale_recovery_batch', 50));

        $processingChildren = AiGeneration::query()
            ->whereNotNull('generation_run_id')
            ->where('generation_status', GenerationStatus::PROCESSING)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('generation_run_id')
            ->limit($batch)
            ->pluck('generation_run_id');

        $queuedRuns = AiGenerationRun::query()
            ->where('status', GenerationRunStatus::Queued)
            ->where('queued_at', '<=', $cutoff)
            ->orderBy('generation_run_id')
            ->limit($batch)
            ->pluck('generation_run_id');

        $queuedChildren = AiGeneration::query()
            ->whereNotNull('generation_run_id')
            ->where('generation_status', GenerationStatus::QUEUED)
            ->where('queued_at', '<=', $cutoff)
            ->orderBy('generation_run_id')
            ->limit($batch)
            ->pluck('generation_run_id');

        $strandedNext = AiGenerationRun::query()
            ->where('status', GenerationRunStatus::Processing)
            ->whereHas('children', fn ($query) => $query->where('generation_status', GenerationStatus::COMPLETED->value))
            ->whereHas('children', fn ($query) => $query->where('generation_status', GenerationStatus::QUEUED->value))
            ->orderBy('generation_run_id')
            ->limit($batch)
            ->pluck('generation_run_id');

        return $processingChildren
            ->merge($queuedRuns)
            ->merge($queuedChildren)
            ->merge($strandedNext)
            ->unique()
            ->sort()
            ->take($batch)
            ->values()
            ->all();
    }
}
