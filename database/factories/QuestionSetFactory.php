<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuestionSetStatus;
use App\Enums\ReviewStatus;
use App\Enums\Visibility;
use App\Models\QuestionSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionSet>
 */
class QuestionSetFactory extends Factory
{
    /**
     * @return array<int|string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'generation_id' => null,
            'title' => fake()->sentence(4),
            'description' => null,
            'subject' => null,
            'grade_level' => null,
            'total_question' => 0,
            'visibility' => Visibility::PRIVATE,
            'status' => QuestionSetStatus::DRAFT,
            'review_status' => ReviewStatus::NOT_SUBMITTED,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ];
    }
}
