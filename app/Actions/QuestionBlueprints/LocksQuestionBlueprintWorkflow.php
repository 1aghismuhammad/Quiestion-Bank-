<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintRow;
use App\Models\QuestionBlueprintRowContext;
use App\Models\QuestionBlueprintSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

trait LocksQuestionBlueprintWorkflow
{
    private function lockUserAndMaterial(int $userId, int $materialId): Material
    {
        User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

        $material = Material::query()
            ->withTrashed()
            ->whereKey($materialId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $material->user_id !== $userId) {
            throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
        }

        return $material;
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

    private function lockBlueprint(int $blueprintId): QuestionBlueprint
    {
        return QuestionBlueprint::query()
            ->whereKey($blueprintId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return Collection<int, QuestionBlueprint>
     */
    private function lockBlueprintsAscending(int $seriesId): Collection
    {
        return QuestionBlueprint::query()
            ->where('blueprint_series_id', $seriesId)
            ->orderBy('blueprint_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, QuestionBlueprintRow>
     */
    private function lockRowsAscending(int $blueprintId): Collection
    {
        return QuestionBlueprintRow::query()
            ->where('blueprint_id', $blueprintId)
            ->orderBy('blueprint_row_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  list<int>  $rowIds
     * @return Collection<int, QuestionBlueprintRowContext>
     */
    private function lockContextsAscending(array $rowIds): Collection
    {
        if ($rowIds === []) {
            return new Collection;
        }

        return QuestionBlueprintRowContext::query()
            ->whereIn('blueprint_row_id', $rowIds)
            ->orderBy('blueprint_row_context_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Canonical confirm/edit lock order after User and Material: Profile Version,
     * Series, Blueprint versions, rows, then context rows.
     *
     * @return array{
     *     profile: MaterialProfileVersion,
     *     series: QuestionBlueprintSeries,
     *     blueprints: Collection<int, QuestionBlueprint>,
     *     blueprint: QuestionBlueprint,
     *     rows: Collection<int, QuestionBlueprintRow>,
     *     contexts: Collection<int, QuestionBlueprintRowContext>
     * }
     */
    private function lockBlueprintGraph(QuestionBlueprint $blueprint): array
    {
        $profile = $this->lockProfileVersion((int) $blueprint->profile_version_id);
        $series = $this->lockSeries((int) $blueprint->blueprint_series_id);
        $blueprints = $this->lockBlueprintsAscending((int) $series->blueprint_series_id);
        $locked = $blueprints->first(
            fn (QuestionBlueprint $candidate): bool => (int) $candidate->blueprint_id === (int) $blueprint->blueprint_id,
        );

        if ($locked === null) {
            throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
        }

        $rows = $this->lockRowsAscending((int) $locked->blueprint_id);
        $contexts = $this->lockContextsAscending(
            $rows->map(fn (QuestionBlueprintRow $row): int => (int) $row->blueprint_row_id)->all(),
        );

        return [
            'profile' => $profile,
            'series' => $series,
            'blueprints' => $blueprints,
            'blueprint' => $locked,
            'rows' => $rows,
            'contexts' => $contexts,
        ];
    }
}
