<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprintRow;
use App\Models\QuestionBlueprintRowContext;
use Illuminate\Support\Collection;

class AssertBlueprintRowContexts
{
    public function __construct(private ResolveBlueprintRowContexts $resolve) {}

    /**
     * @param  Collection<int, QuestionBlueprintRow>|list<array<string, mixed>>  $rows
     * @param  Collection<int, QuestionBlueprintRowContext>|null  $contexts
     */
    public function handle(
        Material $material,
        MaterialProfileVersion $profile,
        iterable $rows,
        ?Collection $contexts = null,
    ): void {
        $found = false;

        foreach ($rows as $row) {
            $found = true;
            $rowContexts = $this->contextsFor($row, $contexts);

            if ($rowContexts === []) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
            }

            $this->resolve->verifyPersisted($material, $profile, $rowContexts);
        }

        if (! $found) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }
    }

    /**
     * @param  QuestionBlueprintRow|array<string, mixed>  $row
     * @param  Collection<int, QuestionBlueprintRowContext>|null  $contexts
     * @return list<QuestionBlueprintRowContext|array<string, mixed>>
     */
    private function contextsFor(QuestionBlueprintRow|array $row, ?Collection $contexts): array
    {
        if (is_array($row)) {
            $items = $row['contexts'] ?? [];

            return is_array($items) ? array_values($items) : [];
        }

        if ($contexts === null) {
            return $row->contexts->all();
        }

        return $contexts
            ->where('blueprint_row_id', $row->blueprint_row_id)
            ->values()
            ->all();
    }
}
