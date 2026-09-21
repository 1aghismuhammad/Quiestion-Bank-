<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\CreateQuestionBlueprintImport;
use App\Enums\BlueprintImportStatus;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\ExtractQuestionBlueprintImport;
use App\Models\Material;
use App\Models\QuestionBlueprintImport;
use App\Models\Role;
use App\Services\Materials\MaterialUsageCalculator;
use App\Services\QuestionBlueprints\BlueprintImportStorageService;
use Database\Seeders\PlanSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;
use Throwable;

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

        Material::factory()->upload()->for($owner)->create(['file_size' => 4096]);
        $usage = $this->app->make(MaterialUsageCalculator::class);
        $usageBefore = $usage->usageInBytes($owner);

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
        $this->assertNotNull($import->queued_at);
        $this->assertNull($import->claimed_at);
        $this->assertNull($import->completed_at);

        Storage::disk('blueprint-imports')->assertExists($import->storage_path);

        Queue::assertPushed(ExtractQuestionBlueprintImport::class, function ($job) use ($import) {
            return $job->importId === $import->import_id;
        });

        $this->assertDatabaseCount('question_blueprints', 0);
        $this->assertDatabaseCount('question_blueprint_rows', 0);
        $this->assertDatabaseCount('ai_usage_logs', 0);
        $this->assertSame($usageBefore, $usage->usageInBytes($owner));
        $this->assertGreaterThan(0, $usageBefore);
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

        Queue::assertPushed(ExtractQuestionBlueprintImport::class, function ($job) {
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

        $this->expectException(BlueprintRejectedException::class);
        $action->handle($foreignUser, $material, $file);
    }

    public function test_admin_cannot_bypass_material_ownership(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $admin = $this->createCompleteUser();
        $admin->roles()->attach(Role::where('name', 'ADMIN')->first());

        $file = UploadedFile::fake()->create('kisi.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $action = $this->app->make(CreateQuestionBlueprintImport::class);

        $this->expectException(BlueprintRejectedException::class);
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

        $this->expectException(BlueprintRejectedException::class);
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

        $this->expectException(BlueprintRejectedException::class);
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

    public function test_active_duplicate_processing_same_material_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        $import = $action->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));
        $import->update(['status' => BlueprintImportStatus::PROCESSING]);

        $this->expectException(ValidationException::class);
        $action->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));
    }

    public function test_active_duplicate_extracted_same_material_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        $import = $action->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));
        $import->update(['status' => BlueprintImportStatus::EXTRACTED]);

        $this->expectException(ValidationException::class);
        $action->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));
    }

    public function test_dispatch_exception_fails_pending_import_and_sanitizes_error(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $this->throwOnJobDispatch(new RuntimeException('queue connection refused: SECRET_DETAIL'));

        $import = $this->app->make(CreateQuestionBlueprintImport::class)
            ->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));

        $this->assertSame(BlueprintImportStatus::FAILED, $import->status);
        $this->assertSame('queue_dispatch_failed', $import->error_code);
        $this->assertSame('Gagal mengantrekan proses file kisi-kisi.', $import->error_message);
        $this->assertNotNull($import->queued_at);
        $this->assertNotNull($import->completed_at);
        $this->assertNull($import->claimed_at);
        $this->assertNull($import->extracted_text);
        $this->assertNull($import->storage_path);
        $this->assertStringNotContainsString('SECRET_DETAIL', (string) json_encode($import->getAttributes()));
    }

    public function test_dispatch_failure_cleanup_exception_keeps_failed_state_and_storage_path(): void
    {
        $real = $this->app->make(BlueprintImportStorageService::class);
        $storage = Mockery::mock(BlueprintImportStorageService::class);
        $storage->shouldReceive('inspect')->andReturnUsing(fn ($file) => $real->inspect($file));
        $storage->shouldReceive('store')->andReturnUsing(fn ($user, $file, $meta) => $real->store($user, $file, $meta));
        $storage->shouldReceive('delete')->andThrow(new RuntimeException('cleanup boom: SECRET_CLEANUP'));
        $this->app->instance(BlueprintImportStorageService::class, $storage);

        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $this->throwOnJobDispatch(new RuntimeException('queue down'));

        $import = $this->app->make(CreateQuestionBlueprintImport::class)
            ->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));

        $this->assertSame(BlueprintImportStatus::FAILED, $import->status);
        $this->assertSame('queue_dispatch_failed', $import->error_code);
        $this->assertSame('Gagal mengantrekan proses file kisi-kisi.', $import->error_message);
        $this->assertNotNull($import->storage_path);
        Storage::disk('blueprint-imports')->assertExists($import->storage_path);
        $this->assertStringNotContainsString('SECRET_CLEANUP', (string) json_encode($import->getAttributes()));
    }

    public function test_dispatch_failure_cleanup_false_return_keeps_failed_state_and_logs_warning(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $real = $this->app->make(BlueprintImportStorageService::class);
        $storage = Mockery::mock(BlueprintImportStorageService::class);
        $storage->shouldReceive('inspect')->andReturnUsing(fn ($file) => $real->inspect($file));
        $storage->shouldReceive('store')->andReturnUsing(fn ($user, $file, $meta) => $real->store($user, $file, $meta));
        $storage->shouldReceive('delete')->andReturn(false);
        $this->app->instance(BlueprintImportStorageService::class, $storage);

        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $this->throwOnJobDispatch(new RuntimeException('queue down: SECRET_FALSE_DELETE'));

        $import = $this->app->make(CreateQuestionBlueprintImport::class)
            ->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));

        $this->assertSame(BlueprintImportStatus::FAILED, $import->status);
        $this->assertSame('queue_dispatch_failed', $import->error_code);
        $this->assertSame('Gagal mengantrekan proses file kisi-kisi.', $import->error_message);
        $this->assertNotNull($import->storage_path);
        Storage::disk('blueprint-imports')->assertExists($import->storage_path);
        $this->assertStringNotContainsString('SECRET_FALSE_DELETE', (string) json_encode($import->getAttributes()));

        $warnings = array_values(array_filter(
            $logged,
            fn (MessageLogged $event): bool => $event->level === 'warning'
                && $event->message === 'Blueprint import dispatch-failure source cleanup failed.',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame($import->import_id, $warnings[0]->context['import_id'] ?? null);
        $this->assertSame('delete_returned_false', $warnings[0]->context['reason'] ?? null);
        $this->assertArrayNotHasKey('path', $warnings[0]->context);
        $this->assertArrayNotHasKey('exception', $warnings[0]->context);
        $this->assertStringNotContainsString('SECRET_FALSE_DELETE', (string) json_encode($warnings[0]->context));
    }

    public function test_dispatch_failure_does_not_regress_extracted_import(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $this->throwOnJobDispatch(new RuntimeException('late dispatch failure'), function (object $job): void {
            QuestionBlueprintImport::query()
                ->where('import_id', $job->importId)
                ->update([
                    'status' => BlueprintImportStatus::EXTRACTED->value,
                    'extracted_text' => 'already extracted',
                    'completed_at' => now(),
                ]);
        });

        $import = $this->app->make(CreateQuestionBlueprintImport::class)
            ->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));

        $this->assertSame(BlueprintImportStatus::EXTRACTED, $import->status);
        $this->assertSame('already extracted', $import->extracted_text);
        $this->assertNotSame('queue_dispatch_failed', $import->error_code);
        $this->assertNotNull($import->storage_path);
    }

    public function test_same_file_allowed_after_dispatch_failed(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $originalDispatcher = $this->app->make(Dispatcher::class);
        $this->throwOnJobDispatch(new RuntimeException('queue connection refused'));

        $action = $this->app->make(CreateQuestionBlueprintImport::class);
        $failed = $action->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));

        $this->assertSame(BlueprintImportStatus::FAILED, $failed->status);

        $this->app->instance(Dispatcher::class, $originalDispatcher);

        $retry = $action->handle($owner, $material, UploadedFile::fake()->create('kisi.docx', 100));

        $this->assertNotSame($failed->import_id, $retry->import_id);
        $this->assertSame(BlueprintImportStatus::PENDING, $retry->status);
        Queue::assertPushed(ExtractQuestionBlueprintImport::class, function ($job) use ($retry) {
            return $job->importId === $retry->import_id;
        });
    }

    /**
     * @param  callable(object):void|null  $beforeThrow
     */
    private function throwOnJobDispatch(Throwable $exception, ?callable $beforeThrow = null): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (object $job) use ($exception, $beforeThrow) {
            if ($beforeThrow !== null) {
                $beforeThrow($job);
            }

            throw $exception;
        });

        $this->app->instance(Dispatcher::class, $dispatcher);
    }
}
