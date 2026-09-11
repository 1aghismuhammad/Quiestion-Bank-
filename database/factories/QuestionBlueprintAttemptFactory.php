<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BlueprintAttemptStatus;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionBlueprintAttempt>
 */
class QuestionBlueprintAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blueprint_id' => QuestionBlueprint::factory(),
            'attempt_number' => 1,
            'provider' => 'fake_blueprint',
            'model' => 'fake-model',
            'prompt_version' => 'blueprint-fill-v1',
            'status' => BlueprintAttemptStatus::Started,
            'started_at' => now(),
        ];
    }
}
