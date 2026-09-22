<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\ProcessQuestionBlueprintImportExtraction;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Jobs\ExtractQuestionBlueprintImport;
use App\Jobs\InterpretQuestionBlueprintImport;
use App\Models\Material;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use App\Services\QuestionBlueprints\BlueprintImportStorageService;
use Database\Seeders\PlanSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Support\Materials\MaterialExtractionFixtures;
use Tests\Support\QuestionBlueprints\BlueprintImportDocxFixtures;
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
        Queue::fake([InterpretQuestionBlueprintImport::class]);
    }

    public function test_job_implements_queue_and_unique_contracts(): void
    {
        $job = new ExtractQuestionBlueprintImport(15);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('15', $job->uniqueId());
        $this->assertSame(900, $job->uniqueFor);
        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([10, 30, 60], $job->backoff);
        $this->assertSame('material-extraction', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            $job->timeout,
        );
        $this->assertSame(90, (int) config('queue.connections.database.retry_after'));
    }

    public function test_without_overlapping_middleware_is_keyed_per_import(): void
    {
        $job = new ExtractQuestionBlueprintImport(22);
        $other = new ExtractQuestionBlueprintImport(23);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('blueprint-import-extraction:22', $middleware[0]->key);
        $this->assertSame(120, $middleware[0]->releaseAfter);
        $this->assertSame(180, $middleware[0]->expiresAfter);
        $this->assertNotSame($middleware[0]->key, $other->middleware()[0]->key);
        $this->assertNotSame($middleware[0]->getLockKey($job), $other->middleware()[0]->getLockKey($other));
        $this->assertStringContainsString((string) $job->importId, $middleware[0]->getLockKey($job));
    }

    public function test_overlap_lock_releases_the_job_without_executing_the_processor(): void
    {
        $job = (new ExtractQuestionBlueprintImport(31))->withFakeQueueInteractions();
        $middleware = $job->middleware()[0];

        $this->assertTrue(Cache::lock($middleware->getLockKey($job), 180)->get());

        $processor = Mockery::mock(ProcessQuestionBlueprintImportExtraction::class);
        $processor->shouldNotReceive('handle');
        $processor->shouldNotReceive('markFailedIfProcessing');

        $invoked = false;
        $middleware->handle($job, function () use (&$invoked, $processor): void {
            $invoked = true;
            $processor->handle(31);
        });

        $this->assertFalse($invoked);
        $job->assertReleased(120);
        $job->assertNotFailed();
    }

    public function test_duplicate_dispatch_for_the_same_import_is_suppressed(): void
    {
        Queue::fake();

        ExtractQuestionBlueprintImport::dispatch(44);
        ExtractQuestionBlueprintImport::dispatch(44);
        ExtractQuestionBlueprintImport::dispatch(45);

        Queue::assertPushed(ExtractQuestionBlueprintImport::class, 2);
        $this->assertCount(
            1,
            Queue::pushed(ExtractQuestionBlueprintImport::class, fn (ExtractQuestionBlueprintImport $job): bool => $job->importId === 44),
        );
        $this->assertCount(
            1,
            Queue::pushed(ExtractQuestionBlueprintImport::class, fn (ExtractQuestionBlueprintImport $job): bool => $job->importId === 45),
        );
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
        $this->assertSame('blueprint-import-structure-v1', $import->structure_schema_version);
        $this->assertSame('paragraph', $import->structured_document['blocks'][0]['type']);
        $this->assertSame('Expected Extraction Text', $import->structured_document['blocks'][0]['text']);
        $this->assertNull($import->storage_path);
        $this->assertSame(BlueprintImportInterpretationStatus::QUEUED, $import->interpretation_status);
        $this->assertSame('blueprint-import-interpret-v1', $import->interpretation_prompt_version);
        $this->assertNotNull($import->interpretation_queued_at);
        $this->assertNull($import->interpretation_claimed_at);
        Queue::assertPushed(InterpretQuestionBlueprintImport::class, 1);
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
        $this->assertNull($import->structured_document);
        $this->assertNull($import->structure_schema_version);
        $this->assertNull($import->storage_path);
        $this->assertNull($import->interpretation_status);
        Queue::assertNotPushed(InterpretQuestionBlueprintImport::class);
    }

    public function test_idempotent_processing(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());
        $import->update([
            'status' => BlueprintImportStatus::EXTRACTED,
            'extracted_text' => 'already extracted',
            'structured_document' => null,
            'structure_schema_version' => null,
        ]);

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::EXTRACTED, $import->status);
        $this->assertSame('already extracted', $import->extracted_text);
        $this->assertNull($import->structured_document);
        $this->assertNull($import->structure_schema_version);
        Queue::assertNotPushed(InterpretQuestionBlueprintImport::class);
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

    public function test_structural_failure_does_not_create_structureless_extracted_row(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $nested = MaterialExtractionFixtures::validDocx(
            '<w:tbl><w:tr><w:tc><w:tbl><w:tr><w:tc><w:p><w:r><w:t>INNER</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:tc></w:tr></w:tbl>',
        );
        $import = $this->createImport($owner, $material, $nested);

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::FAILED, $import->status);
        $this->assertNull($import->extracted_text);
        $this->assertNull($import->structured_document);
        $this->assertNull($import->structure_schema_version);
        $this->assertNotSame(BlueprintImportStatus::EXTRACTED, $import->status);
    }

    public function test_valid_table_docx_persists_structure_then_cleans_up(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, BlueprintImportDocxFixtures::adjacentTableCells());

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::EXTRACTED, $import->status);
        $this->assertSame('table', $import->structured_document['blocks'][0]['type']);
        $this->assertSame(['ALPHA'], $import->structured_document['blocks'][0]['rows'][0]['cells'][0]['paragraphs']);
        $this->assertSame(['BETA'], $import->structured_document['blocks'][0]['rows'][0]['cells'][1]['paragraphs']);
        $this->assertStringContainsString('ALPHA', $import->extracted_text);
        $this->assertSame('blueprint-import-structure-v1', $import->structure_schema_version);
        $this->assertNull($import->storage_path);
        $this->assertSame(BlueprintImportInterpretationStatus::QUEUED, $import->interpretation_status);
        Queue::assertPushed(InterpretQuestionBlueprintImport::class, 1);
    }

    public function test_operational_failure_is_retryable_and_preserves_source(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());
        $path = $import->storage_path;

        $mockStorage = Mockery::mock(BlueprintImportStorageService::class);
        $mockStorage->shouldReceive('get')->andThrow(new RuntimeException('Connection failed'));
        $mockStorage->shouldNotReceive('delete');
        $this->app->instance(BlueprintImportStorageService::class, $mockStorage);

        try {
            $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);
            $this->fail('Operational extraction failure must propagate for queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Connection failed', $exception->getMessage());
        }

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::PROCESSING, $import->status);
        $this->assertSame($path, $import->storage_path);
        $this->assertNull($import->extracted_text);
        $this->assertNull($import->structured_document);
        $this->assertNull($import->structure_schema_version);
        $this->assertNull($import->error_code);
        $this->assertNull($import->completed_at);
        Storage::disk('blueprint-imports')->assertExists($path);
    }

    public function test_job_failed_cleans_up_and_marks_failed(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());
        // Set status to processing as it would be if it failed mid-flight
        $import->update(['status' => BlueprintImportStatus::PROCESSING]);

        $job = new ExtractQuestionBlueprintImport($import->import_id);
        $job->failed(new RuntimeException('All retries exhausted'));

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::FAILED, $import->status);
        $this->assertNull($import->storage_path);
    }

    public function test_extracted_queued_unclaimed_redispatches_without_rereading_source(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());
        $import->update([
            'status' => BlueprintImportStatus::EXTRACTED,
            'extracted_text' => 'frozen text',
            'structured_document' => [
                'blocks' => [[
                    'type' => 'paragraph',
                    'ordinal' => 0,
                    'text' => 'frozen structure',
                ]],
            ],
            'structure_schema_version' => 'blueprint-import-structure-v1',
            'interpretation_status' => BlueprintImportInterpretationStatus::QUEUED,
            'interpretation_prompt_version' => 'blueprint-import-interpret-v1',
            'interpretation_queued_at' => now(),
            'interpretation_claimed_at' => null,
        ]);

        $mockStorage = Mockery::mock(BlueprintImportStorageService::class);
        $mockStorage->shouldNotReceive('get');
        $mockStorage->shouldNotReceive('delete');
        $this->app->instance(BlueprintImportStorageService::class, $mockStorage);

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::EXTRACTED, $import->status);
        $this->assertSame('frozen text', $import->extracted_text);
        $this->assertSame('frozen structure', $import->structured_document['blocks'][0]['text']);
        $this->assertSame(BlueprintImportInterpretationStatus::QUEUED, $import->interpretation_status);
        Queue::assertPushed(InterpretQuestionBlueprintImport::class, 1);
    }

    public function test_interpretation_dispatch_failure_does_not_fail_extraction(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $import = $this->createImport($owner, $material, $this->createValidDocxBytes());

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue connection refused'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->app->make(ProcessQuestionBlueprintImportExtraction::class)->handle($import->import_id);

        $import->refresh();
        $this->assertSame(BlueprintImportStatus::EXTRACTED, $import->status);
        $this->assertNotNull($import->extracted_text);
        $this->assertNotNull($import->structured_document);
        $this->assertSame(BlueprintImportInterpretationStatus::FAILED, $import->interpretation_status);
        $this->assertSame('queue_dispatch_failed', $import->interpretation_error_code);
        $this->assertNull($import->storage_path);
    }

    private function createImport(User $owner, Material $material, string $content): QuestionBlueprintImport
    {
        $path = $owner->id.'/test.docx';
        Storage::disk('blueprint-imports')->put($path, $content);

        return QuestionBlueprintImport::query()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => DB::table('material_profile_versions')->where('material_id', $material->material_id)->first()->profile_version_id,
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
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test-'.uniqid().'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Expected Extraction Text</w:t></w:r></w:p></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $content = file_get_contents($path);
        @unlink($path);

        return $content;
    }
}
