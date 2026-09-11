<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Material;
use App\Models\QuestionBlueprintSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionBlueprintSeries>
 */
class QuestionBlueprintSeriesFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'material_id' => fn (array $attributes): int => (int) Material::factory()->text()->create([
                'user_id' => $attributes['user_id'],
            ])->material_id,
        ];
    }

    public function forOwner(User $user, Material $material): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'material_id' => $material->material_id,
        ]);
    }
}
