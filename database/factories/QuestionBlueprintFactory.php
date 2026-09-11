<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintSource;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionBlueprint>
 */
class QuestionBlueprintFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = 'Fixture material content for blueprint drafts.';

        return [
            'blueprint_series_id' => QuestionBlueprintSeries::factory(),
            'user_id' => fn (array $attributes): int => (int) QuestionBlueprintSeries::query()
                ->whereKey($attributes['blueprint_series_id'])
                ->value('user_id'),
            'material_id' => fn (array $attributes): int => (int) QuestionBlueprintSeries::query()
                ->whereKey($attributes['blueprint_series_id'])
                ->value('material_id'),
            'profile_version_id' => null,
            'version' => 1,
            'lifecycle_status' => BlueprintLifecycleStatus::Draft,
            'source' => BlueprintSource::Manual,
            'ai_fill_status' => BlueprintAiFillStatus::None,
            'assessment_type' => AssessmentType::FORMATIVE,
            'title' => 'Kisi-kisi formatif',
            'material_content_hash' => hash('sha256', $content),
            'material_file_hash' => null,
            'extractor_implementation' => (string) config('material_profile.extractor_implementation'),
        ];
    }

    public function forOwner(User $user, Material $material, QuestionBlueprintSeries $series): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'material_id' => $material->material_id,
            'blueprint_series_id' => $series->blueprint_series_id,
        ]);
    }
}
