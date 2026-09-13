<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Enums\DifficultyLevel;
use App\Enums\GenerationRunErrorCode;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Models\QuestionBlueprintRow;

class AssertAdvancedRunQualification
{
    /**
     * @param  iterable<int, QuestionBlueprintRow>|list<array<string, mixed>>  $rows
     */
    public function handle(iterable $rows, bool $shuffleQuestions, bool $shuffleOptions): void
    {
        $total = 0;
        $difficulties = [];

        foreach ($rows as $row) {
            $requested = $row instanceof QuestionBlueprintRow
                ? (int) $row->requested_count
                : (int) ($row['requested_count'] ?? 0);
            $difficulty = $row instanceof QuestionBlueprintRow
                ? $row->difficulty
                : ($row['difficulty'] ?? null);
            $value = $difficulty instanceof DifficultyLevel
                ? $difficulty->value
                : (string) $difficulty;

            $total += $requested;
            $difficulties[$value] = true;
        }

        if ($total > 10) {
            return;
        }

        if (count($difficulties) > 1 || $shuffleQuestions || $shuffleOptions) {
            return;
        }

        throw new GenerationRunRejectedException(GenerationRunErrorCode::AdvancedFeatureRequired);
    }
}
