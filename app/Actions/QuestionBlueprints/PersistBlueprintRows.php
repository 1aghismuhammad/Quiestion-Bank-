<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintRowOrigin;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintRow;
use App\Models\QuestionBlueprintRowContext;

class PersistBlueprintRows
{
    public function __construct(private AssertSimpleBlueprintShape $assertShape) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function replace(QuestionBlueprint $blueprint, array $rows, BlueprintRowOrigin $origin): void
    {
        $this->assertShape->handle($rows);

        $existing = QuestionBlueprintRow::query()
            ->where('blueprint_id', $blueprint->blueprint_id)
            ->orderBy('blueprint_row_id')
            ->get();

        if ($existing->isNotEmpty()) {
            QuestionBlueprintRowContext::query()
                ->whereIn(
                    'blueprint_row_id',
                    $existing->map(fn (QuestionBlueprintRow $row): int => (int) $row->blueprint_row_id)->all(),
                )
                ->delete();

            QuestionBlueprintRow::query()
                ->where('blueprint_id', $blueprint->blueprint_id)
                ->delete();
        }

        foreach (array_values($rows) as $index => $row) {
            $created = QuestionBlueprintRow::query()->create([
                'blueprint_id' => $blueprint->blueprint_id,
                'sort_order' => $index + 1,
                'objective' => trim((string) $row['objective']),
                'topic' => trim((string) $row['topic']),
                'indicator' => trim((string) $row['indicator']),
                'cognitive_level' => $row['cognitive_level'] instanceof CognitiveLevel
                    ? $row['cognitive_level']
                    : CognitiveLevel::from((string) $row['cognitive_level']),
                'difficulty' => $row['difficulty'] instanceof DifficultyLevel
                    ? $row['difficulty']
                    : DifficultyLevel::from((string) $row['difficulty']),
                'question_type' => QuestionType::MULTIPLE_CHOICE,
                'requested_count' => (int) $row['requested_count'],
                'origin' => ($row['origin'] ?? null) instanceof BlueprintRowOrigin
                    ? $row['origin']
                    : $origin,
            ]);

            $contexts = $row['contexts'] ?? [];

            if (! is_array($contexts) || $contexts === []) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
            }

            foreach ($contexts as $rank => $context) {
                QuestionBlueprintRowContext::query()->create([
                    'blueprint_row_id' => $created->blueprint_row_id,
                    'profile_element_id' => $context['profile_element_id'] ?? null,
                    'profile_chunk_id' => $context['profile_chunk_id'] ?? null,
                    'char_start' => (int) $context['char_start'],
                    'char_end' => (int) $context['char_end'],
                    'context_hash' => (string) $context['context_hash'],
                    'rank' => (int) ($context['rank'] ?? ($rank + 1)),
                ]);
            }
        }
    }
}
