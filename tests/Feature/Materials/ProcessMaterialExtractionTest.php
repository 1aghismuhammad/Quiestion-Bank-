<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\ProcessMaterialExtraction;
use App\Contracts\Materials\MaterialContentExtractor;
use App\Contracts\Materials\MaterialFileStore;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Models\Material;
use App\Models\User;
use App\Services\Materials\Extraction\DocxExtractor;
use App\Services\Materials\Extraction\MaterialExtractorRouter;
use App\Services\Materials\Extraction\PdfExtractor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Materials\FakeMaterialFileStore;
use Tests\Support\Materials\MaterialExtractionFixtures;
use Tests\TestCase;

class ProcessMaterialExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_upload_completes_with_ready_status_and_content(): void
    {
        [$store, $material] = $this->pendingTxt();

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame(ExtractionStatus::COMPLETED, $material->extraction_status);
        $this->assertSame("Hello TXT\n", $material->content);
        $this->assertContains('exists', $store->calls);
        $this->assertContains('read', $store->calls);
    }

    public function test_failed_upload_can_be_claimed_and_completed(): void
    {
        [$store, $material] = $this->pendingTxt(extractionStatus: ExtractionStatus::FAILED);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame(ExtractionStatus::COMPLETED, $material->extraction_status);
        $this->assertSame("Hello TXT\n", $material->content);
    }

    public function test_upload_draft_processing_resumes_without_a_second_claim(): void
    {
        [$store, $material] = $this->pendingTxt(extractionStatus: ExtractionStatus::PROCESSING);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame(ExtractionStatus::COMPLETED, $material->extraction_status);
        $this->assertSame("Hello TXT\n", $material->content);
    }

    public function test_fresh_claim_is_a_noop_if_state_changes_before_extraction(): void
    {
        [$store, $material] = $this->pendingTxt();
        $materialId = $material->material_id;
        $archivedAfterClaim = false;

        DB::listen(function (QueryExecuted $query) use ($materialId, &$archivedAfterClaim): void {
            if ($archivedAfterClaim) {
                return;
            }

            $sql = strtolower($query->sql);

            if (! str_contains($sql, 'update') || ! str_contains($sql, 'extraction_status')) {
                return;
            }

            if (! in_array(ExtractionStatus::PROCESSING->value, $query->bindings, true)) {
                return;
            }

            $archivedAfterClaim = true;

            Material::query()
                ->where('material_id', $materialId)
                ->update([
                    'status' => MaterialStatus::ARCHIVED,
                ]);
        });

        $this->action($store)->handle($materialId);

        $material->refresh();

        $this->assertTrue($archivedAfterClaim);
        $this->assertSame(MaterialStatus::ARCHIVED, $material->status);
        $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
        $this->assertNull($material->content);
        $this->assertSame([], $store->calls);
    }

    public function test_missing_material_is_a_noop(): void
    {
        $store = new FakeMaterialFileStore;

        $this->action($store)->handle(9_999_999);

        $this->assertSame([], $store->calls);
        $this->assertDatabaseCount('materials', 0);
    }

    public function test_soft_deleted_material_is_a_noop(): void
    {
        [$store, $material] = $this->pendingTxt();
        $material->delete();

        $this->action($store)->handle($material->material_id);

        $this->assertNotNull($material->fresh());
        $this->assertTrue($material->fresh()->trashed());
        $this->assertSame(ExtractionStatus::PENDING, $material->fresh()->extraction_status);
        $this->assertSame([], $store->calls);
    }

    public function test_text_material_is_a_noop(): void
    {
        $store = new FakeMaterialFileStore;
        $material = Material::factory()->text()->create([
            'content' => 'keep-text',
        ]);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(SourceType::TEXT, $material->source_type);
        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame(ExtractionStatus::NOT_REQUIRED, $material->extraction_status);
        $this->assertSame('keep-text', $material->content);
        $this->assertSame([], $store->calls);
    }

    public function test_not_required_extraction_is_a_noop(): void
    {
        $store = new FakeMaterialFileStore;
        $user = User::factory()->create();
        $material = Material::factory()->upload()->create([
            'user_id' => $user->id,
            'file_path' => $user->id.'/'.fake()->uuid().'.txt',
            'mime_type' => 'text/plain',
            'content' => 'already-present',
            'extraction_status' => ExtractionStatus::NOT_REQUIRED,
            'status' => MaterialStatus::DRAFT,
        ]);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(ExtractionStatus::NOT_REQUIRED, $material->extraction_status);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame('already-present', $material->content);
        $this->assertSame([], $store->calls);
    }

    public function test_archived_material_is_a_noop(): void
    {
        [$store, $material] = $this->pendingTxt();
        $material->update(['status' => MaterialStatus::ARCHIVED]);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(MaterialStatus::ARCHIVED, $material->status);
        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
        $this->assertNull($material->content);
        $this->assertSame([], $store->calls);
    }

    public function test_completed_material_is_a_noop_and_content_is_unchanged(): void
    {
        $store = new FakeMaterialFileStore;
        $user = User::factory()->create();
        $material = Material::factory()->upload()->create([
            'user_id' => $user->id,
            'file_path' => $user->id.'/'.fake()->uuid().'.txt',
            'mime_type' => 'text/plain',
            'content' => 'do-not-overwrite',
            'extraction_status' => ExtractionStatus::COMPLETED,
            'status' => MaterialStatus::READY,
        ]);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame(ExtractionStatus::COMPLETED, $material->extraction_status);
        $this->assertSame('do-not-overwrite', $material->content);
        $this->assertSame([], $store->calls);
    }

    public function test_ready_processing_is_a_noop(): void
    {
        [$store, $material] = $this->pendingTxt(extractionStatus: ExtractionStatus::PROCESSING);
        $material->update(['status' => MaterialStatus::READY]);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
        $this->assertNull($material->content);
        $this->assertSame([], $store->calls);
    }

    public function test_archived_processing_is_a_noop(): void
    {
        [$store, $material] = $this->pendingTxt(extractionStatus: ExtractionStatus::PROCESSING);
        $material->update(['status' => MaterialStatus::ARCHIVED]);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(MaterialStatus::ARCHIVED, $material->status);
        $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
        $this->assertNull($material->content);
        $this->assertSame([], $store->calls);
    }

    public function test_text_processing_is_a_noop(): void
    {
        $store = new FakeMaterialFileStore;
        $material = Material::factory()->text()->create([
            'content' => 'keep-text',
            'extraction_status' => ExtractionStatus::PROCESSING,
            'status' => MaterialStatus::DRAFT,
        ]);

        $this->action($store)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(SourceType::TEXT, $material->source_type);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
        $this->assertSame('keep-text', $material->content);
        $this->assertSame([], $store->calls);
    }

    public function test_soft_deleted_processing_is_a_noop(): void
    {
        [$store, $material] = $this->pendingTxt(extractionStatus: ExtractionStatus::PROCESSING);
        $material->delete();

        $this->action($store)->handle($material->material_id);

        $trashed = Material::withTrashed()->find($material->material_id);

        $this->assertNotNull($trashed);
        $this->assertTrue($trashed->trashed());
        $this->assertSame(ExtractionStatus::PROCESSING, $trashed->extraction_status);
        $this->assertSame([], $store->calls);
    }

    public function test_wrong_owner_path_is_unrecoverable_and_leaves_processing(): void
    {
        $store = new FakeMaterialFileStore;
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $path = $foreign->id.'/'.fake()->uuid().'.txt';
        $store->files[$path] = MaterialExtractionFixtures::utf8Txt();

        $material = Material::factory()->upload()->create([
            'user_id' => $user->id,
            'file_name' => $path,
            'file_path' => $path,
            'mime_type' => 'text/plain',
        ]);

        try {
            $this->action($store)->handle($material->material_id);
            $this->fail('Wrong owner path must be unrecoverable.');
        } catch (UnrecoverableMaterialExtractionException) {
            $material->refresh();

            $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
            $this->assertSame(MaterialStatus::DRAFT, $material->status);
            $this->assertNull($material->content);
            $this->assertNotContains('read', $store->calls);
        }
    }

    public function test_traversal_and_malformed_paths_are_unrecoverable(): void
    {
        $user = User::factory()->create();
        $uuid = fake()->uuid();

        $invalidPaths = [
            '',
            '/'.$user->id.'/'.$uuid.'.txt',
            'C:/'.$user->id.'/'.$uuid.'.txt',
            $user->id.'\\'.$uuid.'.txt',
            $user->id."/\0".$uuid.'.txt',
            $user->id.'/./'.$uuid.'.txt',
            $user->id.'/../'.$user->id.'/'.$uuid.'.txt',
            $user->id.'//'.$uuid.'.txt',
        ];

        foreach ($invalidPaths as $path) {
            $store = new FakeMaterialFileStore;
            $material = Material::factory()->upload()->create([
                'user_id' => $user->id,
                'file_name' => 'lesson.txt',
                'file_path' => $path === '' ? '' : $path,
                'mime_type' => 'text/plain',
            ]);

            try {
                $this->action($store)->handle($material->material_id);
                $this->fail('Path ['.$path.'] must be unrecoverable.');
            } catch (UnrecoverableMaterialExtractionException) {
                $this->assertSame(ExtractionStatus::PROCESSING, $material->fresh()->extraction_status);
                $this->assertNotContains('read', $store->calls);
            }
        }
    }

    public function test_missing_source_file_is_unrecoverable_and_leaves_processing(): void
    {
        $store = new FakeMaterialFileStore;
        $user = User::factory()->create();
        $material = Material::factory()->upload()->create([
            'user_id' => $user->id,
            'file_path' => $user->id.'/'.fake()->uuid().'.txt',
            'mime_type' => 'text/plain',
        ]);

        try {
            $this->action($store)->handle($material->material_id);
            $this->fail('Missing source file must be unrecoverable.');
        } catch (UnrecoverableMaterialExtractionException) {
            $material->refresh();

            $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
            $this->assertSame(MaterialStatus::DRAFT, $material->status);
            $this->assertNull($material->content);
            $this->assertContains('exists', $store->calls);
            $this->assertNotContains('read', $store->calls);
        }
    }

    public function test_router_unrecoverable_exception_propagates_and_leaves_processing(): void
    {
        [$store, $material] = $this->pendingTxt(
            contents: MaterialExtractionFixtures::invalidUtf8Txt(),
        );

        try {
            $this->action($store)->handle($material->material_id);
            $this->fail('Router unrecoverable failure must propagate.');
        } catch (UnrecoverableMaterialExtractionException) {
            $material->refresh();

            $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
            $this->assertSame(MaterialStatus::DRAFT, $material->status);
            $this->assertNull($material->content);
        }
    }

    public function test_file_read_infrastructure_failure_bubbles_and_leaves_processing(): void
    {
        [$store, $material] = $this->pendingTxt();
        $store->readFailure = new RuntimeException('disk timeout');

        try {
            $this->action($store)->handle($material->material_id);
            $this->fail('Infrastructure read failure must bubble.');
        } catch (RuntimeException $exception) {
            $this->assertSame('disk timeout', $exception->getMessage());
            $this->assertNotInstanceOf(UnrecoverableMaterialExtractionException::class, $exception);

            $material->refresh();

            $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
            $this->assertSame(MaterialStatus::DRAFT, $material->status);
            $this->assertNull($material->content);
        }
    }

    public function test_stale_success_update_does_not_overwrite_changed_state(): void
    {
        [$store, $material] = $this->pendingTxt();
        $originalContent = $material->content;

        $txt = new class($material->material_id) implements MaterialContentExtractor
        {
            public function __construct(private int $materialId) {}

            public function extract(string $contents): string
            {
                Material::query()
                    ->where('material_id', $this->materialId)
                    ->update([
                        'status' => MaterialStatus::ARCHIVED,
                    ]);

                return 'should-not-persist';
            }
        };

        $router = new MaterialExtractorRouter($txt, new PdfExtractor, new DocxExtractor);

        $this->action($store, $router)->handle($material->material_id);

        $material->refresh();

        $this->assertSame(MaterialStatus::ARCHIVED, $material->status);
        $this->assertSame(ExtractionStatus::PROCESSING, $material->extraction_status);
        $this->assertSame($originalContent, $material->content);
        $this->assertNotSame('should-not-persist', $material->content);
    }

    public function test_mark_failed_if_processing_transitions_draft_processing_only(): void
    {
        [$store, $material] = $this->pendingTxt(extractionStatus: ExtractionStatus::PROCESSING);
        $material->update(['content' => 'keep-existing']);

        $this->action($store)->markFailedIfProcessing($material->material_id);
        $this->action($store)->markFailedIfProcessing($material->material_id);

        $material->refresh();

        $this->assertSame(ExtractionStatus::FAILED, $material->extraction_status);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame('keep-existing', $material->content);
    }

    public function test_mark_failed_if_processing_does_not_change_completed_ready_archived_or_deleted(): void
    {
        $store = new FakeMaterialFileStore;
        $action = $this->action($store);

        $completed = Material::factory()->upload()->create([
            'content' => 'completed-content',
            'extraction_status' => ExtractionStatus::COMPLETED,
            'status' => MaterialStatus::READY,
        ]);
        $readyProcessing = Material::factory()->upload()->create([
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

        $action->markFailedIfProcessing($completed->material_id);
        $action->markFailedIfProcessing($readyProcessing->material_id);
        $action->markFailedIfProcessing($archived->material_id);
        $action->markFailedIfProcessing($deleted->material_id);

        $this->assertSame(ExtractionStatus::COMPLETED, $completed->fresh()->extraction_status);
        $this->assertSame(MaterialStatus::READY, $completed->fresh()->status);
        $this->assertSame('completed-content', $completed->fresh()->content);

        $this->assertSame(ExtractionStatus::PROCESSING, $readyProcessing->fresh()->extraction_status);
        $this->assertSame(MaterialStatus::READY, $readyProcessing->fresh()->status);
        $this->assertSame('ready-content', $readyProcessing->fresh()->content);

        $this->assertSame(ExtractionStatus::PROCESSING, $archived->fresh()->extraction_status);
        $this->assertSame(MaterialStatus::ARCHIVED, $archived->fresh()->status);
        $this->assertSame('archived-content', $archived->fresh()->content);

        $trashed = Material::withTrashed()->find($deleted->material_id);
        $this->assertNotNull($trashed);
        $this->assertTrue($trashed->trashed());
        $this->assertSame(ExtractionStatus::PROCESSING, $trashed->extraction_status);
        $this->assertSame('deleted-content', $trashed->content);
    }

    public function test_extension_is_taken_from_file_path_not_file_name(): void
    {
        $store = new FakeMaterialFileStore;
        $user = User::factory()->create();
        $path = $user->id.'/'.fake()->uuid().'.txt';
        $store->files[$path] = MaterialExtractionFixtures::utf8Txt();

        $material = Material::factory()->upload()->create([
            'user_id' => $user->id,
            'file_name' => 'lesson.pdf',
            'file_path' => $path,
            'mime_type' => 'text/plain',
        ]);

        $this->action($store)->handle($material->material_id);

        $this->assertSame("Hello TXT\n", $material->fresh()->content);
        $this->assertSame(ExtractionStatus::COMPLETED, $material->fresh()->extraction_status);
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

    private function action(
        MaterialFileStore $store,
        ?MaterialExtractorRouter $router = null,
    ): ProcessMaterialExtraction {
        $this->app->instance(MaterialFileStore::class, $store);

        return new ProcessMaterialExtraction(
            $store,
            $router ?? $this->app->make(MaterialExtractorRouter::class),
        );
    }
}
