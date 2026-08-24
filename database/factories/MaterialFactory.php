<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'source_type' => SourceType::TEXT,
            'file_name' => null,
            'file_path' => null,
            'file_size' => null,
            'file_hash' => null,
            'mime_type' => null,
            'content' => fake()->paragraphs(3, true),
            'extraction_status' => ExtractionStatus::NOT_REQUIRED,
            'status' => MaterialStatus::READY,
        ];
    }

    public function text(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_type' => SourceType::TEXT,
            'file_name' => null,
            'file_path' => null,
            'file_size' => null,
            'file_hash' => null,
            'mime_type' => null,
            'content' => fake()->paragraphs(3, true),
            'extraction_status' => ExtractionStatus::NOT_REQUIRED,
            'status' => MaterialStatus::READY,
        ]);
    }

    public function upload(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_type' => SourceType::UPLOAD,
            'file_name' => fake()->unique()->lexify('material-????.pdf'),
            'file_path' => 'materials/'.fake()->uuid().'.pdf',
            'file_size' => fake()->numberBetween(1024, 10_485_760),
            'file_hash' => hash('sha256', fake()->unique()->uuid()),
            'mime_type' => 'application/pdf',
            'content' => null,
            'extraction_status' => ExtractionStatus::PENDING,
            'status' => MaterialStatus::DRAFT,
        ]);
    }

    public function extracting(): static
    {
        return $this->upload()->state(fn (array $attributes): array => [
            'extraction_status' => ExtractionStatus::PROCESSING,
        ]);
    }

    public function failed(): static
    {
        return $this->upload()->state(fn (array $attributes): array => [
            'extraction_status' => ExtractionStatus::FAILED,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MaterialStatus::ARCHIVED,
        ]);
    }
}
