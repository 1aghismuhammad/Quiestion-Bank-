<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaterialProfileStatus;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MaterialProfileVersion>
 */
class MaterialProfileVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = 'Fixture material content for profile analysis.';

        return [
            'material_id' => Material::factory()->text()->state([
                'content' => $content,
            ]),
            'user_id' => fn (array $attributes): int => (int) Material::query()
                ->whereKey($attributes['material_id'])
                ->value('user_id'),
            'version' => 1,
            'status' => MaterialProfileStatus::QUEUED,
            'workflow_token' => (string) Str::uuid(),
            'queued_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
            'material_content_hash' => hash('sha256', $content),
            'material_file_hash' => null,
            'extractor_implementation' => (string) config('material_profile.extractor_implementation'),
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
