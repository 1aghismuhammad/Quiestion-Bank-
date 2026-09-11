<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Enums\GenerationRunErrorCode;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\AiGenerationRunItemSpan;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

trait LocksGenerationRun
{
    private function lockUser(int $userId): User
    {
        return User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    private function lockUserAndRun(int $runId): AiGenerationRun
    {
        return $this->lockCanonicalRunGraph($runId)['run'];
    }

    private function lockMaterial(int $materialId): Material
    {
        return Material::query()
            ->withTrashed()
            ->whereKey($materialId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Canonical C+D worker/recovery lock order:
     * User → Material → Run → items (sort_order) → children (child_index) → usage → Attempts.
     * Post-provider persistence continues with Profile Version → item spans → referenced elements/chunks.
     *
     * @return array{
     *     run: AiGenerationRun,
     *     material: Material,
     *     items: Collection<int, AiGenerationRunItem>,
     *     children: Collection<int, AiGeneration>,
     *     usage: AiUsageLog,
     *     attempts: Collection<int, AiGenerationAttempt>
     * }
     */
    private function lockCanonicalRunGraph(int $runId): array
    {
        $preview = AiGenerationRun::query()->whereKey($runId)->first();

        if ($preview === null) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::ValidationFailed);
        }

        $this->lockUser((int) $preview->user_id);
        $material = $this->lockMaterial((int) $preview->material_id);

        $run = AiGenerationRun::query()
            ->whereKey($runId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $run->user_id !== (int) $preview->user_id
            || (int) $run->material_id !== (int) $preview->material_id
            || (int) $material->user_id !== (int) $run->user_id) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::ValidationFailed);
        }

        $items = AiGenerationRunItem::query()
            ->where('generation_run_id', $runId)
            ->orderBy('sort_order')
            ->orderBy('generation_run_item_id')
            ->lockForUpdate()
            ->get();

        $children = $this->lockRunChildrenAscending($runId);
        $usage = $this->lockRunUsage($run);
        $generationIds = $children->map(fn (AiGeneration $child): int => (int) $child->generation_id)->all();
        $attempts = $generationIds === []
            ? new Collection
            : AiGenerationAttempt::query()
                ->whereIn('generation_id', $generationIds)
                ->orderBy('attempt_id')
                ->lockForUpdate()
                ->get();

        return [
            'run' => $run,
            'material' => $material,
            'items' => $items,
            'children' => $children,
            'usage' => $usage,
            'attempts' => $attempts,
        ];
    }

    /**
     * Canonical C+D post-provider persistence lock order:
     * User → Material → Run → items (sort_order) → children (child_index) → usage → Attempts
     * → Profile Version → item spans (item id, rank) → referenced elements/chunks (ascending ids).
     *
     * @return array{
     *     run: AiGenerationRun,
     *     material: Material,
     *     items: Collection<int, AiGenerationRunItem>,
     *     children: Collection<int, AiGeneration>,
     *     usage: AiUsageLog,
     *     attempts: Collection<int, AiGenerationAttempt>,
     *     profile: MaterialProfileVersion,
     *     spans: Collection<int, AiGenerationRunItemSpan>,
     *     elements: Collection<int, MaterialProfileElement>,
     *     chunks: Collection<int, MaterialProfileChunk>
     * }
     */
    private function lockCanonicalRunPersistenceGraph(int $runId): array
    {
        $graph = $this->lockCanonicalRunGraph($runId);
        $profile = $this->lockProfileVersion((int) $graph['run']->profile_version_id);

        $itemIds = $graph['items']
            ->map(fn (AiGenerationRunItem $item): int => (int) $item->generation_run_item_id)
            ->all();

        $spans = $itemIds === []
            ? new Collection
            : AiGenerationRunItemSpan::query()
                ->whereIn('generation_run_item_id', $itemIds)
                ->orderBy('generation_run_item_id')
                ->orderBy('rank')
                ->orderBy('generation_run_item_span_id')
                ->lockForUpdate()
                ->get();

        $elementIds = $spans
            ->pluck('profile_element_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $chunkIds = $spans
            ->pluck('profile_chunk_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $elements = $elementIds === []
            ? new Collection
            : MaterialProfileElement::query()
                ->whereIn('profile_element_id', $elementIds)
                ->orderBy('profile_element_id')
                ->lockForUpdate()
                ->get();

        $chunks = $chunkIds === []
            ? new Collection
            : MaterialProfileChunk::query()
                ->whereIn('profile_chunk_id', $chunkIds)
                ->orderBy('profile_chunk_id')
                ->lockForUpdate()
                ->get();

        return [
            ...$graph,
            'profile' => $profile,
            'spans' => $spans,
            'elements' => $elements,
            'chunks' => $chunks,
        ];
    }

    /**
     * @return Collection<int, AiGeneration>
     */
    private function lockRunChildrenAscending(int $runId): Collection
    {
        return AiGeneration::query()
            ->where('generation_run_id', $runId)
            ->orderBy('child_index')
            ->orderBy('generation_id')
            ->lockForUpdate()
            ->get();
    }

    private function lockRunUsage(AiGenerationRun $run): AiUsageLog
    {
        $usage = AiUsageLog::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->lockForUpdate()
            ->first();

        if ($usage === null
            || (int) $usage->user_id !== (int) $run->user_id
            || $usage->generation_id !== null) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::ValidationFailed);
        }

        return $usage;
    }

    private function lockProfileVersion(int $profileVersionId): MaterialProfileVersion
    {
        return MaterialProfileVersion::query()
            ->whereKey($profileVersionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockSeries(int $seriesId): QuestionBlueprintSeries
    {
        return QuestionBlueprintSeries::query()
            ->whereKey($seriesId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockConfirmedBlueprint(int $blueprintId): QuestionBlueprint
    {
        $seriesId = QuestionBlueprint::query()->whereKey($blueprintId)->value('blueprint_series_id');

        if ($seriesId === null) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::ValidationFailed);
        }

        $this->lockSeries((int) $seriesId);

        return QuestionBlueprint::query()
            ->whereKey($blueprintId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
