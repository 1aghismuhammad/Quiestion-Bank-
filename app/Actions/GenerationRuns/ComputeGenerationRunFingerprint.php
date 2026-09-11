<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Enums\GenerationRunMode;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintRow;
use JsonException;

class ComputeGenerationRunFingerprint
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return hash('sha256', serialize($payload));
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param  iterable<int, QuestionBlueprintRow>  $rows
     */
    public function forStart(
        int $userId,
        QuestionBlueprint $blueprint,
        string $outputLanguage,
        int $totalRequested,
        int $creditsRequired,
        string $contentHash,
        ?string $fileHash,
        string $extractor,
        ?int $parentRunId,
        iterable $rows,
    ): string {
        $rowSnapshots = [];

        foreach ($rows as $row) {
            $rowSnapshots[] = [
                'blueprint_row_id' => (int) $row->blueprint_row_id,
                'sort_order' => (int) $row->sort_order,
                'objective' => (string) $row->objective,
                'topic' => (string) $row->topic,
                'indicator' => (string) $row->indicator,
                'cognitive_level' => $row->cognitive_level->value,
                'difficulty' => $row->difficulty->value,
                'question_type' => $row->question_type->value,
                'requested_count' => (int) $row->requested_count,
            ];
        }

        return $this->hash([
            'user_id' => $userId,
            'material_id' => (int) $blueprint->material_id,
            'blueprint_id' => (int) $blueprint->blueprint_id,
            'blueprint_series_id' => (int) $blueprint->blueprint_series_id,
            'blueprint_version' => (int) $blueprint->version,
            'profile_version_id' => $blueprint->profile_version_id,
            'assessment_type' => $blueprint->assessment_type->value,
            'output_language' => $outputLanguage,
            'mode' => GenerationRunMode::Simple->value,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'total_requested_questions' => $totalRequested,
            'credits_required' => $creditsRequired,
            'material_content_hash' => $contentHash,
            'material_file_hash' => $fileHash,
            'extractor_implementation' => $extractor,
            'parent_run_id' => $parentRunId,
            'rows' => $rowSnapshots,
        ]);
    }
}
