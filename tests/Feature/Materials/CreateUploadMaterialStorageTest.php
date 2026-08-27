<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\CreateUploadMaterial;
use App\Contracts\Materials\MaterialFileStore;
use App\Data\Materials\MaterialFileMetadata;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Models\Material;
use App\Models\User;
use App\Services\Materials\MaterialStorageService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CreateUploadMaterialStorageTest extends TestCase
{
    use RefreshDatabase;

    private const DUPLICATE_MESSAGE = 'File yang sama sudah diunggah.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('materials');
    }

    public function test_successful_upload_persists_draft_pending_material_and_uuid_file(): void
    {
        $user = User::factory()->create();
        $content = 'real-storage-upload';
        $file = $this->pdfUpload($content);

        $material = $this->action()->handle($user, 'Lesson PDF', $file);

        $this->assertDatabaseCount('materials', 1);
        $this->assertSame(SourceType::UPLOAD, $material->source_type);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
        $this->assertSame(hash('sha256', $content), $material->file_hash);
        $this->assertMatchesRegularExpression(
            '/^'.$user->id.'\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/',
            (string) $material->file_path,
        );
        $this->assertTrue(Str::isUuid(pathinfo((string) $material->file_path, PATHINFO_FILENAME)));
        Storage::disk('materials')->assertExists((string) $material->file_path);
        $this->assertTrue($user->fresh()->materials->first()->is($material));
    }

    public function test_same_user_duplicate_is_rejected_before_a_second_store(): void
    {
        $user = User::factory()->create();
        $content = 'duplicate-precheck-bytes';

        $first = $this->action()->handle($user, 'First', $this->pdfUpload($content));

        try {
            $this->action()->handle($user, 'Second', $this->pdfUpload($content));
            $this->fail('Expected duplicate uploads to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(self::DUPLICATE_MESSAGE, $exception->errors()['file'][0] ?? null);
        }

        $this->assertDatabaseCount('materials', 1);
        $this->assertSame([(string) $first->file_path], Storage::disk('materials')->allFiles());
    }

    public function test_same_hash_is_allowed_for_a_different_user(): void
    {
        $content = 'shared-hash-bytes';
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $materialA = $this->action()->handle($userA, 'User A', $this->pdfUpload($content));
        $materialB = $this->action()->handle($userB, 'User B', $this->pdfUpload($content));

        $this->assertDatabaseCount('materials', 2);
        $this->assertSame($materialA->file_hash, $materialB->file_hash);
        $this->assertNotSame($materialA->user_id, $materialB->user_id);
        $this->assertSame($userA->id, $materialA->user_id);
        $this->assertSame($userB->id, $materialB->user_id);
        $this->assertCount(2, Storage::disk('materials')->allFiles());
        $this->assertStringStartsWith($userA->id.'/', (string) $materialA->file_path);
        $this->assertStringStartsWith($userB->id.'/', (string) $materialB->file_path);
        Storage::disk('materials')->assertExists((string) $materialA->file_path);
        Storage::disk('materials')->assertExists((string) $materialB->file_path);
    }

    public function test_soft_deleted_same_hash_is_rejected_without_a_new_file(): void
    {
        $user = User::factory()->create();
        $content = 'soft-deleted-hash-bytes';

        $material = $this->action()->handle($user, 'Original', $this->pdfUpload($content));
        $originalPath = (string) $material->file_path;
        $material->delete();

        try {
            $this->action()->handle($user, 'Reupload', $this->pdfUpload($content));
            $this->fail('Expected a soft-deleted hash to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(self::DUPLICATE_MESSAGE, $exception->errors()['file'][0] ?? null);
        }

        $this->assertSame(1, Material::withTrashed()->where('user_id', $user->id)->count());
        $this->assertSame(0, Material::query()->where('user_id', $user->id)->count());
        $this->assertSoftDeleted('materials', ['material_id' => $material->material_id]);
        $this->assertSame([$originalPath], Storage::disk('materials')->allFiles());
    }

    public function test_generic_db_failure_after_store_compensates_the_uuid_file(): void
    {
        $missingOwner = User::factory()->make(['id' => 9_999_999]);
        $missingOwner->exists = true;

        try {
            $this->action()->handle($missingOwner, 'Broken persist', $this->pdfUpload());
            $this->fail('Expected persistence to fail for a missing owner.');
        } catch (QueryException) {
        } catch (ValidationException) {
            $this->fail('Generic DB failure must not be mapped to a duplicate validation error.');
        }

        $this->assertDatabaseCount('materials', 0);
        $this->assertSame([], Storage::disk('materials')->allFiles());
    }

    public function test_unique_loss_after_store_compensates_loser_and_keeps_winner_row(): void
    {
        $user = User::factory()->create();
        $inner = new MaterialStorageService;
        $store = new class($inner) implements MaterialFileStore
        {
            public ?string $loserPath = null;

            public ?Material $winner = null;

            public function __construct(private MaterialFileStore $inner) {}

            public function inspect(UploadedFile $file): MaterialFileMetadata
            {
                return $this->inner->inspect($file);
            }

            public function store(User $owner, UploadedFile $file, MaterialFileMetadata $metadata): MaterialFileMetadata
            {
                $stored = $this->inner->store($owner, $file, $metadata);
                $this->loserPath = $stored->path;
                $this->winner = Material::factory()->upload()->for($owner)->create([
                    'file_hash' => $stored->hash,
                    'file_path' => 'winner-placeholder.pdf',
                ]);

                return $stored;
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }

            public function read(string $path): string
            {
                return $this->inner->read($path);
            }

            public function delete(string $path): void
            {
                $this->inner->delete($path);
            }
        };

        try {
            $this->action($store)->handle($user, 'Loser', $this->pdfUpload('unique-loss-bytes'));
            $this->fail('Expected unique-loss to become a duplicate validation error.');
        } catch (ValidationException $exception) {
            $this->assertSame(self::DUPLICATE_MESSAGE, $exception->errors()['file'][0] ?? null);
        }

        $this->assertNotNull($store->winner);
        $this->assertNotNull($store->loserPath);
        $this->assertSame(1, Material::query()->where('user_id', $user->id)->where('file_hash', $store->winner->file_hash)->count());
        $this->assertTrue(Material::query()->findOrFail($store->winner->material_id)->is($store->winner));
        $this->assertSame('winner-placeholder.pdf', $store->winner->fresh()->file_path);
        Storage::disk('materials')->assertMissing($store->loserPath);
        $this->assertSame([], Storage::disk('materials')->allFiles());
    }

    public function test_cleanup_failure_preserves_the_original_db_exception_and_logs_a_warning(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $inner = new MaterialStorageService;
        $cleanup = new RuntimeException('cleanup failed');
        $store = new class($inner, $cleanup) implements MaterialFileStore
        {
            public ?string $loserPath = null;

            public function __construct(
                private MaterialFileStore $inner,
                private RuntimeException $cleanup,
            ) {}

            public function inspect(UploadedFile $file): MaterialFileMetadata
            {
                return $this->inner->inspect($file);
            }

            public function store(User $owner, UploadedFile $file, MaterialFileMetadata $metadata): MaterialFileMetadata
            {
                $stored = $this->inner->store($owner, $file, $metadata);
                $this->loserPath = $stored->path;

                return $stored;
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }

            public function read(string $path): string
            {
                return $this->inner->read($path);
            }

            public function delete(string $path): void
            {
                throw $this->cleanup;
            }
        };

        $missingOwner = User::factory()->make(['id' => 9_999_999]);
        $missingOwner->exists = true;
        $originalName = 'Secret Name.pdf';

        try {
            $this->action($store)->handle(
                $missingOwner,
                'Broken persist',
                $this->pdfUpload('cleanup-failure-bytes', $originalName),
            );
            $this->fail('Expected persistence to fail for a missing owner.');
        } catch (QueryException) {
        } catch (RuntimeException $exception) {
            $this->fail('Cleanup exception replaced the original DB exception: '.$exception->getMessage());
        }

        $this->assertNotNull($store->loserPath);
        $warnings = array_values(array_filter(
            $logged,
            fn (MessageLogged $event): bool => $event->level === 'warning',
        ));
        $this->assertNotEmpty($warnings);

        $warning = $warnings[0];
        $this->assertSame($store->loserPath, $warning->context['path'] ?? null);
        $this->assertSame($cleanup::class, $warning->context['exception'] ?? null);
        $this->assertStringNotContainsString($originalName, $warning->message);
        $this->assertStringNotContainsString($originalName, json_encode($warning->context) ?: '');
        Storage::disk('materials')->assertExists($store->loserPath);
    }

    private function action(?MaterialFileStore $store = null): CreateUploadMaterial
    {
        return new CreateUploadMaterial($store ?? new MaterialStorageService);
    }

    private function pdfUpload(string $content = 'upload-bytes', string $name = 'lesson.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
