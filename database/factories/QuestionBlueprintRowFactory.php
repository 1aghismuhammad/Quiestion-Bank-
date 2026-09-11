<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BlueprintRowOrigin;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionBlueprintRow>
 */
class QuestionBlueprintRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blueprint_id' => QuestionBlueprint::factory(),
            'sort_order' => 1,
            'objective' => 'Peserta mampu menjelaskan konsep utama materi.',
            'topic' => 'Konsep utama',
            'indicator' => 'Peserta menyebutkan dua contoh penerapan.',
            'cognitive_level' => CognitiveLevel::Understand,
            'difficulty' => DifficultyLevel::MEDIUM,
            'question_type' => QuestionType::MULTIPLE_CHOICE,
            'requested_count' => 5,
            'origin' => BlueprintRowOrigin::Manual,
        ];
    }
}
