<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BlueprintImportStatus;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionBlueprintImportFactory extends Factory
{
    protected $model = QuestionBlueprintImport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'material_id' => Material::factory(),
            'profile_version_id' => MaterialProfileVersion::factory(),
            'status' => BlueprintImportStatus::PENDING,
            'original_file_name' => $this->faker->word().'.docx',
            'storage_path' => $this->faker->uuid().'.docx',
            'file_size' => $this->faker->numberBetween(1000, 50000),
            'file_hash' => hash('sha256', $this->faker->text()),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extracted_text' => null,
            'structured_document' => null,
            'structure_schema_version' => null,
            'material_content_hash' => hash('sha256', 'content'),
            'material_file_hash' => hash('sha256', 'file'),
            'extractor_implementation' => 'v1',
        ];
    }
}
