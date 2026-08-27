<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\ProcessMaterialExtraction;
use App\Contracts\Materials\MaterialFileStore;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Jobs\ExtractMaterialContent;
use App\Models\Material;
use App\Models\User;
use App\Services\Materials\Extraction\MaterialExtractorRouter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Support\Materials\FakeMaterialFileStore;
use Tests\Support\Materials\MaterialExtractionFixtures;
use Tests\TestCase;

class ExtractMaterialContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_implements_queue_and_unique_contracts(): void
    {
        $job = new ExtractMaterialContent(15);

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
    }

    public function test_without_overlapping_middleware_uses_approved_timing(): void
    {
        $job = new ExtractMaterialContent(22);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('material-extraction:22', $middleware[0]->key);
        $this->assertSame(120, $middleware[0]->releaseAfter);
        $this->assertSame(180, $middleware[0]->expiresAfter);
        $this->assertNotNull($middleware[0]->releaseAfter);
        $this->assertStringContainsString('22', $middleware[0]->key);
        $this->assertStringContainsString((string) $job->materialId, $middleware[0]->getLockKey($job));
    }

    public function test_overlap_lock_releases_the_job_without_executing_the_action(): void
    {
        $job = (new ExtractMaterialContent(31))->withFakeQueueInteractions();
        $middleware = $job->middleware()[0];

        $this->assertTrue(Cache::lock($middleware->getLockKey($job), 180)->get());

        $action = Mockery::mock(ProcessMaterialExtraction::class);
        $action->shouldNotReceive('handle');
        $action->shouldNotReceive('markFailedIfProcessing');

        $invoked = false;
        $middleware->handle($job, function () use (&$invoked, $action): void {
            $invoked = true;
            $action->handle(31);
        });

        $this->assertFalse($invoked);
        $job->assertReleased(120);
        $job->assertNotFailed();
    }

    public function test_duplicate_dispatch_for_the_same_material_is_suppressed(): void
    {
        Queue::fake();

        ExtractMaterialContent::dispatch(44);
        ExtractMaterialContent::dispatch(44);
        ExtractMaterialContent::dispatch(45);

        Queue::assertPushed(ExtractMaterialContent::class, 2);
        Queue::assertPushed(ExtractMaterialContent::class, function (ExtractMaterialContent $job): bool {
            return $job->materialId === 44;
        });
        $this->assertCount(
            1,
            Queue::pushed(ExtractMaterialContent::class, fn (ExtractMaterialContent $job): bool => $job->materialId === 44),
        );
        $this->assertCount(
            1,
            Queue::pushed(ExtractMaterialContent::class, fn (ExtractMaterialContent $job): bool => $job->materialId === 45),
        );
    }

    public function test_handle_completes_pending_material_through_the_action(): void
    {
        [$store, $material] = $this->pendingTxt();
        $this->app->instance(MaterialFileStore::class, $store);

        $job = new ExtractMaterialContent($material->material_id);
        $job->handle($this->app->make(ProcessMaterialExtraction::class));

        $material->refresh();

        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame(ExtractionStatus::COMPLETED, $material->extraction_status);
        $this->assertSame("Hello TXT\n", $material->content);
    }

    public function test_unrecoverable_failure_marks_processing_failed_and_fails_the_job(): void
    {
        $store = new FakeMaterialFileStore;
        $user = User::factory()->create();
        $material = Material::factory()->upload()->create([
            'user_id' => $user->id,
            'file_path' => $user->id.'/'.fake()->uuid().'.txt',
            'mime_type' => 'text/plain',
            'extraction_status' => ExtractionStatus::PENDING,
            'status' => MaterialStatus::DRAFT,
        ]);
        $this->app->instance(MaterialFileStore::class, $store);

        $job = (new ExtractMaterialContent($material->material_id))->withFakeQueueInteractions();
        $job->handle($this->app->make(ProcessMaterialExtraction::class));

        $material->refresh();

        $this->assertSame(ExtractionStatus::FAILED, $material->extraction_status);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertNull($material->content);
        $job->assertFailed();
        $job->assertFailedWith(UnrecoverableMaterialExtractionException::class);
    }

    public function test_infrastructure_read_failure_bubbles_without_marking_failed(): void
    {
        [$store, $material] = $this->pendingTxt();
        $store->readFailure = new RuntimeException('disk timeout');
        $this->app->instance(MaterialFileStore::class, $store);

        $job = (new ExtractMaterialContent($material->material_id))->withFakeQueueInteractions();

        try {
            $job->handle($this->app->make(ProcessMaterialExtraction::class));
            $this->fail('Infrastructure failures must bubble from the job.');
        } catch (RuntimeException $exception) {
            $this->assertSame('disk timeout', $exception->getMessage());
            $this->assertNotInstanceOf(UnrecoverableMaterialExtractionException::class, $exception);

            $material->refresh();

            $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
            $this->assertSame(MaterialStatus::DRAFT, $material->status);
            $job->assertNotFailed();
        }
    }

    public function test_failed_hook_marks_draft_processing_as_failed_without_clearing_content(): void
    {
        [$store, $material] = $this->pendingTxt(extractionStatus: ExtractionStatus::PROCESSING);
        $material->update(['content' => 'keep-existing']);
        $this->app->instance(MaterialFileStore::class, $store);

        $job = new ExtractMaterialContent($material->material_id);
        $job->failed(new RuntimeException('timeout'));
        $job->failed(new RuntimeException('timeout-again'));

        $material->refresh();

        $this->assertSame(ExtractionStatus::FAILED, $material->extraction_status);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame('keep-existing', $material->content);
    }

    public function test_failed_hook_does_not_change_completed_ready_archived_or_deleted_materials(): void
    {
        $completed = Material::factory()->upload()->create([
            'content' => 'completed-content',
            'extraction_status' => ExtractionStatus::COMPLETED,
            'status' => MaterialStatus::READY,
        ]);
        $ready = Material::factory()->upload()->create([
            'content' => 'ready-content',
            'extraction_status' => ExtractionStatus::PROCESSING,
            'status' => MaterialStatus::READY,
        ]);
        $archived = Material::factory()->upload()->archived()->create([
            'content' => 'archived-content',
            'extraction_status' => ExtractionStatus::PROCESSING,
        ]);
        $deleted = Material::factory()->upload()->create([
            'content' => 'deleted-content',
            'extraction_status' => ExtractionStatus::PROCESSING,
            'status' => MaterialStatus::DRAFT,
        ]);
        $deleted->delete();

        (new ExtractMaterialContent($completed->material_id))->failed(null);
        (new ExtractMaterialContent($ready->material_id))->failed(null);
        (new ExtractMaterialContent($archived->material_id))->failed(null);
        (new ExtractMaterialContent($deleted->material_id))->failed(null);

        $this->assertSame(ExtractionStatus::COMPLETED, $completed->fresh()->extraction_status);
        $this->assertSame(MaterialStatus::READY, $completed->fresh()->status);
        $this->assertSame('completed-content', $completed->fresh()->content);

        $this->assertSame(ExtractionStatus::PROCESSING, $ready->fresh()->extraction_status);
        $this->assertSame(MaterialStatus::READY, $ready->fresh()->status);
        $this->assertSame('ready-content', $ready->fresh()->content);

        $this->assertSame(ExtractionStatus::PROCESSING, $archived->fresh()->extraction_status);
        $this->assertSame(MaterialStatus::ARCHIVED, $archived->fresh()->status);
        $this->assertSame('archived-content', $archived->fresh()->content);

        $trashed = Material::withTrashed()->find($deleted->material_id);
        $this->assertNotNull($trashed);
        $this->assertTrue($trashed->trashed());
        $this->assertSame(ExtractionStatus::PROCESSING, $trashed->extraction_status);
        $this->assertSame('deleted-content', $trashed->content);
    }

    public function test_container_resolves_router_with_txt_pdf_and_docx_extractors(): void
    {
        $router = $this->app->make(MaterialExtractorRouter::class);
        $action = $this->app->make(ProcessMaterialExtraction::class);

        $this->assertInstanceOf(MaterialExtractorRouter::class, $router);
        $this->assertInstanceOf(ProcessMaterialExtraction::class, $action);

        $this->assertSame(
            "Hello TXT\n",
            $router->extract(MaterialExtractionFixtures::utf8Txt(), 'txt', 'text/plain'),
        );
        $this->assertStringContainsString(
            'Hello PDF',
            $router->extract(MaterialExtractionFixtures::extractablePdf(), 'pdf', 'application/pdf'),
        );
        $this->assertSame(
            "Hello DOCX\n",
            $router->extract(
                MaterialExtractionFixtures::simpleParagraphDocx(),
                'docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
        );
    }

    /**
     * @return array{0: FakeMaterialFileStore, 1: Material}
     */
    private function pendingTxt(
        ExtractionStatus $extractionStatus = ExtractionStatus::PENDING,
        string $contents = "Hello TXT\n",
    ): array {
        $store = new FakeMaterialFileStore;
        $user = User::factory()->create();
        $path = $user->id.'/'.fake()->uuid().'.txt';
        $store->files[$path] = $contents;

        $material = Material::factory()->upload()->create([
            'user_id' => $user->id,
            'file_name' => 'lesson.txt',
            'file_path' => $path,
            'mime_type' => 'text/plain',
            'file_size' => strlen($contents),
            'content' => null,
            'extraction_status' => $extractionStatus,
            'status' => MaterialStatus::DRAFT,
        ]);

        return [$store, $material];
    }
}
