<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationStatus;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGeneration>
 */
class AiGenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_type' => AssessmentType::FORMATIVE,
            'difficulty_level' => DifficultyLevel::MEDIUM,
            'question_type' => QuestionType::MULTIPLE_CHOICE,
            'question_count' => 5,
            'generation_status' => GenerationStatus::QUEUED,
            'error_message' => null,
            'attempt_number' => 1,
            'parent_generation_id' => null,
            'queued_at' => now(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (AiGeneration $generation): void {
            if ($generation->user_id === null && $generation->material_id !== null) {
                $generation->user_id = Material::query()->findOrFail($generation->material_id)->user_id;
            }

            if ($generation->user_id === null) {
                $generation->user_id = User::factory()->create()->id;
            }

            if ($generation->material_id === null) {
                $generation->material_id = Material::factory()->create([
                    'user_id' => $generation->user_id,
                ])->material_id;
            }
        });
    }
}
