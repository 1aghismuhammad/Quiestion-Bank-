<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiGenerationRunItem;
use App\Models\AiGenerationRunItemSpan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGenerationRunItemSpan>
 */
class AiGenerationRunItemSpanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = 'Fixture';

        return [
            'generation_run_item_id' => AiGenerationRunItem::factory(),
            'char_start' => 0,
            'char_end' => mb_strlen($text, 'UTF-8'),
            'rank' => 1,
            'content_hash' => hash('sha256', $text),
        ];
    }
}
