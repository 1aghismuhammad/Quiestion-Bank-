<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Exceptions\GenerationRuns\GenerationRunTopologyException;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Collection;

class AssertRunChildTopology
{
    /**
     * @param  Collection<int, AiGenerationRunItem>  $items
     * @param  Collection<int, AiGeneration>  $children
     */
    public function handle(
        AiGenerationRun $run,
        Collection $items,
        Collection $children,
        AiUsageLog $usage,
        ?AiGeneration $current = null,
        bool $requireProcessingRun = true,
    ): void {
        if ($run->status->isTerminal()) {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }

        if ($requireProcessingRun && $run->status !== GenerationRunStatus::Processing) {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }

        if ((int) $usage->user_id !== (int) $run->user_id
            || $usage->generation_id !== null
            || (int) $usage->generation_run_id !== (int) $run->generation_run_id
            || $usage->status !== UsageStatus::RESERVED) {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }

        $itemCount = $items->count();
        $childCount = $children->count();

        if ($itemCount < 1 || $itemCount !== $childCount) {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }

        $expected = range(1, $itemCount);
        $itemOrders = $items->map(fn (AiGenerationRunItem $item): int => (int) $item->sort_order)->sort()->values()->all();
        $childIndexes = $children->map(fn (AiGeneration $child): int => (int) $child->child_index)->sort()->values()->all();

        if ($itemOrders !== $expected || $childIndexes !== $expected) {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }

        if ($items->pluck('sort_order')->unique()->count() !== $itemCount
            || $children->pluck('child_index')->unique()->count() !== $childCount
            || $children->pluck('generation_run_item_id')->unique()->count() !== $childCount) {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }

        foreach ($items as $item) {
            $matches = $children->filter(
                fn (AiGeneration $child): bool => (int) $child->generation_run_item_id === (int) $item->generation_run_item_id,
            );

            if ($matches->count() !== 1) {
                throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
            }

            $child = $matches->first();

            if ((int) $child->generation_run_id !== (int) $run->generation_run_id
                || (int) $child->user_id !== (int) $run->user_id
                || (int) $child->material_id !== (int) $run->material_id
                || (int) $child->child_index !== (int) $item->sort_order
                || (int) $child->question_count !== (int) $item->requested_count) {
                throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
            }
        }

        if ($current === null) {
            return;
        }

        $lockedCurrent = $children->first(
            fn (AiGeneration $child): bool => (int) $child->generation_id === (int) $current->generation_id,
        );

        if ($lockedCurrent === null
            || $lockedCurrent->generation_status !== GenerationStatus::PROCESSING
            || ! is_string($lockedCurrent->execution_token)
            || $lockedCurrent->execution_token === '') {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }

        $prior = $children->filter(
            fn (AiGeneration $child): bool => (int) $child->child_index < (int) $lockedCurrent->child_index,
        );
        $later = $children->filter(
            fn (AiGeneration $child): bool => (int) $child->child_index > (int) $lockedCurrent->child_index,
        );
        $processing = $children->filter(
            fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::PROCESSING,
        );

        if ($prior->contains(fn (AiGeneration $child): bool => $child->generation_status !== GenerationStatus::COMPLETED)
            || $later->contains(fn (AiGeneration $child): bool => $child->generation_status !== GenerationStatus::QUEUED)
            || $processing->count() !== 1) {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }
    }
}
