<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /**
     * @return array<int|string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_set_id' => QuestionSet::factory(),
            'question_number' => 1,
            'question_text' => fake()->sentence(),
            'question_type' => QuestionType::MULTIPLE_CHOICE,
            'difficulty_level' => DifficultyLevel::MEDIUM,
            'correct_answer' => null,
            'explanation' => fake()->sentence(),
            'rubric' => null,
            'points' => 1,
        ];
    }
}
