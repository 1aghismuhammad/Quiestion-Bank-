<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Contracts\Materials\MaterialContentExtractor;
use App\Contracts\Materials\MaterialFileStore;
use App\Models\User;
use App\Services\Materials\MaterialStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class MaterialStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_materials_disk_configuration_is_isolated_and_unserved(): void
    {
        $disk = config('filesystems.disks.materials');

        $this->assertIsArray($disk);
        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/materials'), $disk['root']);
        $this->assertSame(false, $disk['serve']);
        $this->assertSame(true, $disk['throw']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertArrayNotHasKey('url', $disk);

        $root = $disk['root'];
        $privateRoot = storage_path('app/private');
        $publicRoot = storage_path('app/public');

        $this->assertFalse(str_starts_with($root, $privateRoot.DIRECTORY_SEPARATOR));
        $this->assertNotSame($privateRoot, $root);
        $this->assertFalse(str_starts_with($root, $publicRoot.DIRECTORY_SEPARATOR));
        $this->assertNotSame($publicRoot, $root);

        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertSame(true, config('filesystems.disks.local.serve'));
        $this->assertSame(storage_path('app/public'), config('filesystems.disks.public.root'));
        $this->assertSame(storage_path('app/public'), config('filesystems.links')[public_path('storage')]);
    }

    public function test_material_file_store_is_bound_to_storage_service(): void
    {
        $this->assertInstanceOf(MaterialStorageService::class, $this->app->make(MaterialFileStore::class));
    }

    public function test_inspect_produces_sha256_of_known_bytes(): void
    {
        $content = 'known-material-bytes';
        $file = UploadedFile::fake()->createWithContent('notes.txt', $content);

        $metadata = $this->service()->inspect($file);

        $this->assertSame(hash('sha256', $content), $metadata->hash);
        $this->assertSame(strlen($content), $metadata->size);
        $this->assertNull($metadata->path);
    }

    public function test_inspect_keeps_original_filename_as_metadata_only(): void
    {
        $file = UploadedFile::fake()->createWithContent('My Lesson.pdf', 'lesson-bytes');

        $metadata = $this->service()->inspect($file);

        $this->assertSame('My Lesson.pdf', $metadata->originalName);
        $this->assertSame('pdf', $metadata->extension);
        $this->assertNull($metadata->path);
    }

    public function test_inspect_uses_server_detected_mime_not_client_mime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mat');
        $this->assertNotFalse($path);
        file_put_contents($path, 'Hello world');

        $file = new UploadedFile($path, 'notes.txt', 'application/octet-stream', null, true);

        try {
            $metadata = $this->service()->inspect($file);

            $this->assertSame($file->getMimeType(), $metadata->mimeType);
            $this->assertNotSame($file->getClientMimeType(), $metadata->mimeType);
        } finally {
            @unlink($path);
        }
    }

    public function test_inspect_rejects_extension_outside_allowlist_without_writing(): void
    {
        $file = UploadedFile::fake()->createWithContent('notes.exe', 'not-allowed');

        $this->assertInspectFailsWithoutWriting($file);
    }

    public function test_inspect_rejects_unavailable_real_path_without_writing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mat');
        $this->assertNotFalse($path);
        file_put_contents($path, 'temporary');

        $file = new UploadedFile($path, 'notes.pdf', 'application/pdf', null, true);
        unlink($path);

        $this->assertInspectFailsWithoutWriting($file);
    }

    public function test_inspect_rejects_empty_file_without_writing(): void
    {
        $file = UploadedFile::fake()->createWithContent('notes.txt', '');

        $this->assertInspectFailsWithoutWriting($file);
    }

    public function test_store_persists_uuid_path_on_materials_disk_only(): void
    {
        Storage::fake('materials');
        Storage::fake('public');
        Storage::fake('local');

        $content = 'persisted-material-bytes';
        $file = UploadedFile::fake()->createWithContent('My Lesson.pdf', $content);
        $user = User::factory()->create();
        $service = $this->service();

        $inspected = $service->inspect($file);
        $stored = $service->store($user, $file, $inspected);

        $this->assertSame($inspected->hash, $stored->hash);
        $this->assertSame('My Lesson.pdf', $stored->originalName);
        $this->assertStringNotContainsString('My Lesson', (string) $stored->path);
        $this->assertSame($content, Storage::disk('materials')->get((string) $stored->path));

        $path = (string) $stored->path;
        $this->assertMatchesRegularExpression(
            '/^'.$user->id.'\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/',
            $path,
        );
        $this->assertTrue(Str::isUuid(pathinfo($path, PATHINFO_FILENAME)));
        $this->assertSame('pdf', pathinfo($path, PATHINFO_EXTENSION));

        Storage::disk('materials')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_exists_returns_true_for_a_file_on_the_private_materials_disk(): void
    {
        Storage::fake('materials');

        $path = '3/'.(string) Str::uuid().'.txt';
        Storage::disk('materials')->put($path, 'payload-bytes');

        $this->assertTrue($this->service()->exists($path));
    }

    public function test_exists_returns_false_for_a_missing_path(): void
    {
        Storage::fake('materials');

        $this->assertFalse($this->service()->exists('3/missing-'.(string) Str::uuid().'.txt'));
    }

    public function test_empty_path_does_not_resolve_to_the_disk_root(): void
    {
        Storage::fake('materials');
        Storage::disk('materials')->put('kept.txt', 'keep-me');

        $this->assertFalse($this->service()->exists(''));
        $this->assertFalse($this->service()->exists('   '));

        try {
            $this->service()->read('');
            $this->fail('Empty path read should fail explicitly.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Material file path is invalid.', $exception->getMessage());
        }

        Storage::disk('materials')->assertExists('kept.txt');
        $this->assertSame('keep-me', Storage::disk('materials')->get('kept.txt'));
    }

    public function test_read_returns_exact_stored_bytes(): void
    {
        Storage::fake('materials');

        $path = '4/'.(string) Str::uuid().'.txt';
        $bytes = "exact-bytes\r\n\0and-null";
        Storage::disk('materials')->put($path, $bytes);

        $this->assertSame($bytes, $this->service()->read($path));
    }

    public function test_read_cannot_read_from_public_or_local_disks(): void
    {
        Storage::fake('materials');
        Storage::fake('public');
        Storage::fake('local');

        $path = '5/'.(string) Str::uuid().'.txt';
        Storage::disk('public')->put($path, 'from-public');
        Storage::disk('local')->put($path, 'from-local');

        $this->assertFalse($this->service()->exists($path));

        try {
            $this->service()->read($path);
            $this->fail('Read should not succeed from public or local disks.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Material file does not exist.', $exception->getMessage());
        }

        Storage::disk('materials')->put($path, 'from-materials');

        $this->assertSame('from-materials', $this->service()->read($path));
        $this->assertNotSame('from-public', $this->service()->read($path));
        $this->assertNotSame('from-local', $this->service()->read($path));
    }

    public function test_read_of_a_missing_file_fails_explicitly(): void
    {
        Storage::fake('materials');

        try {
            $this->service()->read('6/missing-'.(string) Str::uuid().'.pdf');
            $this->fail('Missing file read should fail explicitly.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Material file does not exist.', $exception->getMessage());
        }
    }

    public function test_exists_and_read_use_materials_disk_without_public_urls(): void
    {
        $exists = $this->methodSource('exists');
        $read = $this->methodSource('read');
        $source = $exists.$read;

        $this->assertStringContainsString('Storage::disk(self::DISK)', $exists);
        $this->assertStringContainsString('Storage::disk(self::DISK)', $read);
        $this->assertStringNotContainsString('url(', $source);
        $this->assertStringNotContainsString("disk('public')", $source);
        $this->assertStringNotContainsString("disk('local')", $source);
        $this->assertStringNotContainsString('->path(', $source);
    }

    public function test_material_content_extractor_contract_has_no_storage_or_format_dependency(): void
    {
        $filename = (new ReflectionClass(MaterialContentExtractor::class))->getFileName();
        $this->assertNotFalse($filename);

        $source = file_get_contents($filename);
        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('Eloquent', $source);
        $this->assertStringNotContainsString('Storage', $source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Smalot', $source);
        $this->assertStringNotContainsString('ZipArchive', $source);
        $this->assertStringNotContainsString('pdf', $source);
        $this->assertStringNotContainsString('docx', $source);
        $this->assertStringNotContainsString('txt', $source);

        $method = new ReflectionMethod(MaterialContentExtractor::class, 'extract');
        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertSame('contents', $method->getParameters()[0]->getName());
        $this->assertSame('string', $method->getParameters()[0]->getType()?->getName());
        $this->assertSame('string', $method->getReturnType()?->getName());
    }

    public function test_delete_removes_the_path_returned_by_store(): void
    {
        Storage::fake('materials');

        $file = UploadedFile::fake()->createWithContent('notes.txt', 'delete-me');
        $user = User::factory()->create();
        $service = $this->service();

        $stored = $service->store($user, $file, $service->inspect($file));
        $path = (string) $stored->path;

        Storage::disk('materials')->assertExists($path);

        $service->delete($path);

        Storage::disk('materials')->assertMissing($path);
    }

    public function test_delete_of_empty_path_is_a_safe_noop(): void
    {
        Storage::fake('materials');

        $file = UploadedFile::fake()->createWithContent('notes.txt', 'keep-me');
        $user = User::factory()->create();
        $service = $this->service();

        $stored = $service->store($user, $file, $service->inspect($file));

        $service->delete('');

        Storage::disk('materials')->assertExists((string) $stored->path);
    }

    public function test_hash_file_belongs_to_inspect_not_store(): void
    {
        $this->assertStringContainsString("hash_file('sha256'", $this->methodSource('inspect'));
        $this->assertStringNotContainsString('hash_file', $this->methodSource('store'));
        $this->assertStringNotContainsString('hash(', $this->methodSource('store'));
    }

    private function service(): MaterialStorageService
    {
        return new MaterialStorageService;
    }

    private function assertInspectFailsWithoutWriting(UploadedFile $file): void
    {
        Storage::fake('materials');

        try {
            $this->service()->inspect($file);
            $this->fail('Inspect should have failed.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], Storage::disk('materials')->allFiles());
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(MaterialStorageService::class, $method);
        $filename = $reflection->getFileName();
        $this->assertNotFalse($filename);

        $lines = file($filename);
        $this->assertNotFalse($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
