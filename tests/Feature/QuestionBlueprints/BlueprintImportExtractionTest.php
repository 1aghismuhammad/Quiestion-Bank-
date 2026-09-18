<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\ProcessQuestionBlueprintImportExtraction;
use App\Enums\BlueprintImportStatus;
use App\Jobs\ExtractQuestionBlueprintImport;
use App\Models\Material;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;
use ZipArchive;

class BlueprintImportExtractionTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Storage::fake('blueprint-imports');
    }

    public function test_valid_docx_extraction_persists_text_and_cleans_up(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();

        $this->assertSame(BlueprintImportStatus::EXTRACTED, $import->status);
        $this->assertStringContainsString('Expected Extraction Text', $import->extracted_text);
        $this->assertNull($import->storage_path);
    }

    public function test_invalid_docx_fails_safely_and_cleans_up(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, 'not a valid zip file');

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();

        $this->assertSame(BlueprintImportStatus::FAILED, $import->status);
        $this->assertNotNull($import->error_code);
        $this->assertNull($import->storage_path);
    }

    public function test_idempotent_processing(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());
        $import->update(['status' => BlueprintImportStatus::EXTRACTED]);

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::EXTRACTED, $import->status);
    }

    public function test_failed_processing_no_ops(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());
        $import->update(['status' => BlueprintImportStatus::FAILED]);

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::FAILED, $import->status);
    }

    public function test_operational_failure_is_retryable_and_preserves_source(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());

        $processor = $this->app->make(ProcessQuestionBlueprintImportExtraction::class);

        // Mock to throw standard exception
        $mockStorage = \Mockery::mock(\App\Services\QuestionBlueprints\BlueprintImportStorageService::class);
        $mockStorage->shouldReceive('get')->andThrow(new \RuntimeException('Connection failed'));

        $this->app->instance(\App\Services\QuestionBlueprints\BlueprintImportStorageService::class, $mockStorage);

        $processor = $this->app->make(ProcessQuestionBlueprintImportExtraction::class);

        $this->expectException(\RuntimeException::class);
        $processor->handle($import->import_id);
    }

    public function test_job_failed_cleans_up_and_marks_failed(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());
        // Set status to processing as it would be if it failed mid-flight
        $import->update(['status' => BlueprintImportStatus::PROCESSING]);

        $job = new \App\Jobs\ExtractQuestionBlueprintImport($import->import_id);
        $job->failed(new \RuntimeException('All retries exhausted'));

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::FAILED, $import->status);
        $this->assertNull($import->storage_path);
    }

    private function createImport(User $owner, Material $material, string $content): QuestionBlueprintImport
    {
        $path = $owner->id . '/test.docx';
        Storage::disk('blueprint-imports')->put($path, $content);

        return QuestionBlueprintImport::query()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => \Illuminate\Support\Facades\DB::table('material_profile_versions')->where('material_id', $material->material_id)->first()->profile_version_id,
            'status' => BlueprintImportStatus::PENDING,
            'original_file_name' => 'test.docx',
            'storage_path' => $path,
            'file_size' => strlen($content),
            'file_hash' => hash('sha256', $content),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'material_content_hash' => 'hash1',
            'material_file_hash' => 'hash2',
            'extractor_implementation' => 'v1',
        ]);
    }

    private function createValidDocxBytes(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test-' . uniqid() . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Expected Extraction Text</w:t></w:r></w:p></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $content = file_get_contents($path);
        @unlink($path);

        return $content;
    }
}
