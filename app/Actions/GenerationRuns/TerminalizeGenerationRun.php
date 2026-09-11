<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Actions\Generations\DetectDuplicateMcqQuestions;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Collection;

class TerminalizeGenerationRun
{
    use LocksGenerationRun;

    public function __construct(
        private ConsumeGenerationRunCredit $consume,
        private ReleaseGenerationRunCredit $release,
        private DetectDuplicateMcqQuestions $duplicates,
    ) {}

    /**
     * Assumes User → Material → Run → items → children → Usage → Attempts are already locked.
     *
     * @param  Collection<int, AiGeneration>  $children
     * @param  Collection<int, AiGenerationRunItem>  $items
     * @param  Collection<int, AiGenerationAttempt>  $attempts
     * @return int|null Next child generation_id to dispatch
     */
    public function apply(
        AiGenerationRun $run,
        Collection $children,
        AiUsageLog $usage,
        ?Collection $items = null,
        ?Collection $attempts = null,
    ): ?int {
        if ($run->status->isTerminal()) {
            return null;
        }

        $items ??= AiGenerationRunItem::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->orderBy('sort_order')
            ->get();
        $attempts ??= new Collection;

        $failed = $children->first(
            fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::FAILED,
        );

        if ($failed !== null) {
            $code = GenerationErrorCode::tryFrom((string) $failed->error_code) ?? GenerationErrorCode::JobFailed;
            $this->closeStartedAttempts($attempts, $code);
            $this->abortQueued($children, GenerationErrorCode::RunAborted);
            $this->clearLeases($children);
            $this->failRun($run, $code);

            if ($usage->status === UsageStatus::RESERVED) {
                $this->release->handle($run);
            }

            return null;
        }

        $processing = $children->filter(
            fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::PROCESSING,
        );

        if ($processing->isNotEmpty()) {
            $run->status = GenerationRunStatus::Processing;
            $run->save();

            return null;
        }

        $nextQueued = $children
            ->filter(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::QUEUED)
            ->sortBy(fn (AiGeneration $child): int => (int) $child->child_index)
            ->first();

        if ($nextQueued !== null) {
            $run->status = GenerationRunStatus::Processing;
            $run->save();

            return (int) $nextQueued->generation_id;
        }

        if (! $this->runSucceeded($run, $items, $children)) {
            $this->closeStartedAttempts($attempts, GenerationErrorCode::IncompleteOutput);
            $this->clearLeases($children);
            $this->failRun($run, GenerationErrorCode::IncompleteOutput);

            if ($usage->status === UsageStatus::RESERVED) {
                $this->release->handle($run);
            }

            return null;
        }

        $run->status = GenerationRunStatus::Completed;
        $run->completed_at = now();
        $run->failed_at = null;
        $run->error_code = null;
        $run->error_message = null;
        $run->save();

        $this->clearLeases($children);

        if ($usage->status === UsageStatus::RESERVED) {
            $this->consume->handle($run);
        }

        return null;
    }

    /**
     * @param  Collection<int, AiGeneration>  $children
     */
    public function abortQueued(Collection $children, GenerationErrorCode $code): void
    {
        foreach ($children as $child) {
            if ($child->generation_status !== GenerationStatus::QUEUED) {
                continue;
            }

            $child->generation_status = GenerationStatus::FAILED;
            $child->error_code = $code->value;
            $child->error_message = $code->userMessage();
            $child->failed_at = now();
            $child->execution_token = null;
            $child->save();
        }
    }

    public function failRun(AiGenerationRun $run, GenerationErrorCode $code): void
    {
        $run->status = GenerationRunStatus::Failed;
        $run->error_code = $code->value;
        $run->error_message = $code->userMessage();
        $run->failed_at = now();
        $run->completed_at = null;
        $run->save();
    }

    /**
     * @param  Collection<int, AiGenerationAttempt>  $attempts
     */
    public function closeStartedAttempts(Collection $attempts, GenerationErrorCode $code): void
    {
        foreach ($attempts as $attempt) {
            if ($attempt->status !== GenerationAttemptStatus::STARTED) {
                continue;
            }

            $attempt->status = GenerationAttemptStatus::FAILED;
            $attempt->safe_error_code = $code->value;
            $attempt->finished_at = now();
            $attempt->save();
        }
    }

    /**
     * @param  Collection<int, AiGeneration>  $children
     */
    public function clearLeases(Collection $children): void
    {
        foreach ($children as $child) {
            if ($child->execution_token === null) {
                continue;
            }

            $child->execution_token = null;
            $child->save();
        }
    }

    /**
     * @param  Collection<int, AiGenerationRunItem>  $items
     * @param  Collection<int, AiGeneration>  $children
     */
    private function runSucceeded(AiGenerationRun $run, Collection $items, Collection $children): bool
    {
        if ($items->count() < 1 || $items->count() !== $children->count()) {
            return false;
        }

        if ($children->contains(fn (AiGeneration $child): bool => $child->generation_status !== GenerationStatus::COMPLETED)) {
            return false;
        }

        if ($children->pluck('child_index')->unique()->count() !== $children->count()
            || $children->pluck('generation_run_item_id')->unique()->count() !== $children->count()) {
            return false;
        }

        $stems = [];
        $acceptedTotal = 0;

        foreach ($items as $item) {
            $matches = $children->filter(
                fn (AiGeneration $candidate): bool => (int) $candidate->generation_run_item_id === (int) $item->generation_run_item_id,
            );

            if ($matches->count() !== 1) {
                return false;
            }

            $child = $matches->first();

            if ($child->generation_status !== GenerationStatus::COMPLETED
                || (int) $child->child_index !== (int) $item->sort_order) {
                return false;
            }

            $set = is_array($child->result_json) ? ValidatedMcqSet::fromStoredJson($child->result_json) : new ValidatedMcqSet([]);

            if ($set->count() !== (int) $child->question_count || $set->count() !== (int) $item->requested_count) {
                return false;
            }

            foreach ($set->questionTexts() as $text) {
                if ($this->duplicates->isDuplicate($text, $stems)) {
                    return false;
                }

                $stems[] = $text;
            }

            $acceptedTotal += $set->count();
        }

        return $acceptedTotal === (int) $run->total_requested_questions;
    }
}
