<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Materials;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\Support\Materials\FakeMaterialFileStore;
use Tests\TestCase;

class FakeMaterialFileStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_user_relative_path_and_exact_bytes(): void
    {
        $user = User::factory()->create();
        $store = new FakeMaterialFileStore;
        $bytes = "exact-bytes\r\n\0and-null";
        $file = UploadedFile::fake()->createWithContent('lesson.bin', $bytes);

        $stored = $store->store($user, $file, $store->inspect($file));
        $path = (string) $stored->path;

        $this->assertSame(['inspect', 'store'], $store->calls);
        $this->assertSame($user->id.'/fake-'.$store->hash.'.bin', $path);
        $this->assertStringStartsWith($user->id.'/', $path);
        $this->assertStringNotContainsString('materials/', $path);
        $this->assertTrue($store->exists($path));
        $this->assertSame($bytes, $store->read($path));
    }

    public function test_delete_removes_in_memory_bytes(): void
    {
        $user = User::factory()->create();
        $store = new FakeMaterialFileStore;
        $file = UploadedFile::fake()->createWithContent('lesson.bin', "keep-\0-me");

        $path = (string) $store->store($user, $file, $store->inspect($file))->path;

        $store->delete($path);

        $this->assertSame(['inspect', 'store', 'delete'], $store->calls);
        $this->assertSame([$path], $store->deleted);
        $this->assertFalse($store->exists($path));

        try {
            $store->read($path);
            $this->fail('Read after delete should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Material file does not exist.', $exception->getMessage());
        }
    }

    public function test_read_failure_override_remains_functional(): void
    {
        $store = new FakeMaterialFileStore;
        $store->files['9/forced.bin'] = 'stored-bytes';
        $store->readFailure = new RuntimeException('forced-read-failure');

        try {
            $store->read('9/forced.bin');
            $this->fail('Forced read failure should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced-read-failure', $exception->getMessage());
        }

        $this->assertTrue($store->exists('9/forced.bin'));
    }
}
