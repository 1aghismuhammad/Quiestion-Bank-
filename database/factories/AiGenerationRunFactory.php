<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssessmentType;
use App\Enums\GenerationRunMode;
use App\Enums\GenerationRunStatus;
use App\Enums\OutputLanguage;
use App\Models\AiGenerationRun;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiGenerationRun>
 */
class AiGenerationRunFactory extends Factory
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
            'blueprint_id' => fn (array $attributes): int => (int) QuestionBlueprint::factory()->create([
                'user_id' => $attributes['user_id'],
                'material_id' => $attributes['material_id'],
            ])->blueprint_id,
            'blueprint_series_id' => fn (array $attributes): int => (int) QuestionBlueprint::query()
                ->whereKey($attributes['blueprint_id'])
                ->value('blueprint_series_id'),
            'blueprint_version' => 1,
            'assessment_type' => AssessmentType::FORMATIVE,
            'output_language' => OutputLanguage::ID,
            'mode' => GenerationRunMode::Simple,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'material_content_hash' => hash('sha256', 'fixture'),
            'extractor_implementation' => (string) config('material_profile.extractor_implementation'),
            'total_requested_questions' => 5,
            'credits_required' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fixture-fingerprint'),
            'status' => GenerationRunStatus::Queued,
            'queued_at' => now(),
        ];
    }
}
