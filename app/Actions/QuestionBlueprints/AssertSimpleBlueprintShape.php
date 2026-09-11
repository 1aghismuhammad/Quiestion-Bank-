<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\QuestionBlueprintRow;
use Illuminate\Support\Collection;

class AssertSimpleBlueprintShape
{
    /**
     * @param  Collection<int, QuestionBlueprintRow>|list<array<string, mixed>>  $rows
     */
    public function handle(iterable $rows): void
    {
        $normalized = [];

        foreach ($rows as $row) {
            $normalized[] = $row instanceof QuestionBlueprintRow
                ? [
                    'question_type' => $row->question_type,
                    'difficulty' => $row->difficulty,
                    'requested_count' => (int) $row->requested_count,
                    'objective' => (string) $row->objective,
                    'topic' => (string) $row->topic,
                    'indicator' => (string) $row->indicator,
                    'cognitive_level' => $row->cognitive_level,
                ]
                : $row;
        }

        $count = count($normalized);
        $maxRows = (int) config('question_blueprint.max_rows', 5);

        if ($count < 1 || $count > $maxRows) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        $difficulties = [];
        $total = 0;
        $maxText = (int) config('question_blueprint.row_text_max_chars', 500);
        $minCount = (int) config('question_blueprint.min_requested_count', 1);
        $maxCount = (int) config('question_blueprint.max_requested_count', 10);
        $maxTotal = (int) config('question_blueprint.max_total_requested', 10);

        foreach ($normalized as $row) {
            $rawType = $row['question_type'] ?? QuestionType::MULTIPLE_CHOICE;
            $type = $rawType instanceof QuestionType
                ? $rawType
                : QuestionType::tryFrom((string) $rawType);
            $difficulty = $row['difficulty'] instanceof DifficultyLevel
                ? $row['difficulty']
                : DifficultyLevel::tryFrom((string) $row['difficulty']);

            if ($type !== QuestionType::MULTIPLE_CHOICE || $difficulty === null) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }

            foreach (['objective', 'topic', 'indicator'] as $field) {
                $text = trim((string) ($row[$field] ?? ''));

                if ($text === '' || mb_strlen($text, 'UTF-8') > $maxText) {
                    throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
                }
            }

            if (($row['cognitive_level'] ?? null) === null) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }

            $requested = (int) $row['requested_count'];

            if ($requested < $minCount || $requested > $maxCount) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }

            $difficulties[] = $difficulty->value;
            $total += $requested;
        }

        if (count(array_unique($difficulties)) !== 1 || $total < 1 || $total > $maxTotal) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }
    }
}
