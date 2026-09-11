<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionBlueprintRow;
use App\Models\QuestionBlueprintRowContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionBlueprintRowContext>
 */
class QuestionBlueprintRowContextFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $excerpt = 'Fixture';

        return [
            'blueprint_row_id' => QuestionBlueprintRow::factory(),
            'profile_element_id' => null,
            'profile_chunk_id' => null,
            'char_start' => 0,
            'char_end' => mb_strlen($excerpt, 'UTF-8'),
            'context_hash' => hash('sha256', $excerpt),
            'rank' => 1,
        ];
    }
}
