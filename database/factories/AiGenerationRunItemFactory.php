<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\QuestionBlueprintRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGenerationRunItem>
 */
class AiGenerationRunItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_run_id' => AiGenerationRun::factory(),
            'blueprint_row_id' => QuestionBlueprintRow::factory(),
            'objective' => 'Peserta mampu menjelaskan konsep utama materi.',
            'topic' => 'Konsep utama',
            'indicator' => 'Peserta menyebutkan dua contoh penerapan.',
            'cognitive_level' => CognitiveLevel::Understand,
            'difficulty' => DifficultyLevel::MEDIUM,
            'question_type' => QuestionType::MULTIPLE_CHOICE,
            'requested_count' => 5,
            'sort_order' => 1,
        ];
    }
}
