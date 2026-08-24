<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialTopic>
 */
class MaterialTopicFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'topic_name' => fake()->unique()->words(3, true),
            'focus_area' => fake()->optional()->sentence(3),
            'chapter' => (string) fake()->numberBetween(1, 12),
            'sub_chapter' => (string) fake()->numberBetween(1, 8),
            'sort_order' => 0,
            'page_start' => fake()->optional()->numberBetween(1, 50),
            'page_end' => fake()->optional()->numberBetween(51, 120),
        ];
    }
}
