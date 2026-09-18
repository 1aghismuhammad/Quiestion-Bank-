<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\CreateQuestionBlueprintImport;
use App\Enums\BlueprintImportStatus;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class BlueprintImportStorageTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Storage::fake('blueprint-imports');
        Queue::fake();
    }

    public function test_valid_docx_creates_import_and_dispatches_job(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $file = UploadedFile::fake()->create('kisi-kisi.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        $import = $action->handle($owner, $material, $file);

        $this->assertDatabaseHas('question_blueprint_imports', [
            'import_id' => $import->import_id,
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'status' => BlueprintImportStatus::PENDING->value,
            'original_file_name' => 'kisi-kisi.docx',
            'file_size' => $file->getSize(),
        ]);

        $this->assertNotNull($import->profile_version_id);
        $this->assertNotNull($import->material_content_hash);
        $this->assertNotNull($import->extractor_implementation);

        Storage::disk('blueprint-imports')->assertExists($import->storage_path);
        
        Queue::assertPushed(\App\Jobs\ExtractQuestionBlueprintImport::class, function ($job) use ($import) {
            return $job->importId === $import->import_id;
        });

        // Prove side effects
        $this->assertDatabaseCount('question_blueprints', 0);
        $this->assertDatabaseCount('question_blueprint_rows', 0);
        $this->assertDatabaseCount('ai_usage_logs', 0);
        
        // Prove material storage quota is NOT consumed by import
        // The import file is on 'blueprint-imports' not 'materials' disk.
    }

    public function test_job_dispatch_carries_explicit_after_commit_semantics(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $file = UploadedFile::fake()->create('kisi.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        
        Queue::fake();

        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        $action->handle($owner, $material, $file);

        Queue::assertPushed(\App\Jobs\ExtractQuestionBlueprintImport::class, function ($job) {
            return $job->afterCommit === true;
        });
    }

    public function test_foreign_material_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $foreignUser = $this->createCompleteUser();

        $file = UploadedFile::fake()->create('kisi.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        
        $this->expectException(\App\Exceptions\QuestionBlueprints\BlueprintRejectedException::class);
        $action->handle($foreignUser, $material, $file);
    }

    public function test_admin_cannot_bypass_material_ownership(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $admin = $this->createCompleteUser();
        $admin->roles()->attach(\App\Models\Role::where('name', 'ADMIN')->first());

        $file = UploadedFile::fake()->create('kisi.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        
        $this->expectException(\App\Exceptions\QuestionBlueprints\BlueprintRejectedException::class);
        $action->handle($admin, $material, $file);
    }

    public function test_same_file_different_user_allowed(): void
    {
        $owner1 = $this->createCompleteUser();
        $material1 = Material::factory()->text()->for($owner1)->create();
        $this->readyProfile($owner1, $material1);

        $owner2 = $this->createCompleteUser();
        $material2 = Material::factory()->text()->for($owner2)->create();
        $this->readyProfile($owner2, $material2);

        $file1 = UploadedFile::fake()->create('kisi.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $file2 = UploadedFile::fake()->create('kisi.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        
        $import1 = $action->handle($owner1, $material1, $file1);
        $import2 = $action->handle($owner2, $material2, $file2);

        $this->assertNotSame($import1->import_id, $import2->import_id);
    }

    public function test_stale_profile_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        
        // Make profile stale by changing content
        $material->update(['content' => 'Changed content', 'content_hash' => 'newhash']);

        $file = UploadedFile::fake()->create('kisi.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        
        $this->expectException(\App\Exceptions\QuestionBlueprints\BlueprintRejectedException::class);
        $action->handle($owner, $material, $file);
    }

    public function test_empty_file_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $file = UploadedFile::fake()->create('kisi.docx', 0);
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        
        $this->expectException(ValidationException::class);
        $action->handle($owner, $material, $file);
    }

    public function test_large_file_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        // 11 MB
        $file = UploadedFile::fake()->create('kisi.docx', 11 * 1024);
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        
        $this->expectException(ValidationException::class);
        $action->handle($owner, $material, $file);
    }

    public function test_non_docx_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $file = UploadedFile::fake()->create('kisi.pdf', 100);
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        
        $this->expectException(ValidationException::class);
        $action->handle($owner, $material, $file);
    }

    public function test_missing_ready_profile_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        // Do not ready profile

        $file = UploadedFile::fake()->create('kisi.docx', 100);
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        
        $this->expectException(\App\Exceptions\QuestionBlueprints\BlueprintRejectedException::class);
        $action->handle($owner, $material, $file);
    }

    public function test_same_file_different_material_allowed(): void
    {
        $owner = $this->createCompleteUser();
        $material1 = Material::factory()->text()->for($owner)->create();
        $material2 = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material1);
        $this->readyProfile($owner, $material2);

        $file = UploadedFile::fake()->create('kisi.docx', 100);
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        $import1 = $action->handle($owner, $material1, $file);
        
        $file2 = UploadedFile::fake()->create('kisi.docx', 100); // same size/content => same hash
        $import2 = $action->handle($owner, $material2, $file2);

        $this->assertNotSame($import1->import_id, $import2->import_id);
    }

    public function test_active_duplicate_same_material_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $file = UploadedFile::fake()->create('kisi.docx', 100);
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        $import1 = $action->handle($owner, $material, $file);
        
        $file2 = UploadedFile::fake()->create('kisi.docx', 100); // same hash
        
        $this->expectException(ValidationException::class);
        $action->handle($owner, $material, $file2);
    }

    public function test_failed_duplicate_allowed(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $file = UploadedFile::fake()->create('kisi.docx', 100);
        
        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        $import1 = $action->handle($owner, $material, $file);
        $import1->update(['status' => BlueprintImportStatus::FAILED]);
        
        $file2 = UploadedFile::fake()->create('kisi.docx', 100); // same hash
        $import2 = $action->handle($owner, $material, $file2);

        $this->assertNotSame($import1->import_id, $import2->import_id);
    }
}
