<?php

declare(strict_types=1);

namespace Tests\Support\Materials;

use App\Contracts\Materials\MaterialFileStore;
use App\Data\Materials\MaterialFileMetadata;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class FakeMaterialFileStore implements MaterialFileStore
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string, string> */
    public array $files = [];

    public ?RuntimeException $readFailure = null;

    public string $hash;

    public function __construct(?string $hash = null)
    {
        $this->hash = $hash ?? hash('sha256', 'fake-material-file');
    }

    public function inspect(UploadedFile $file): MaterialFileMetadata
    {
        $this->calls[] = 'inspect';

        return new MaterialFileMetadata(
            originalName: $file->getClientOriginalName(),
            extension: $file->getClientOriginalExtension(),
            mimeType: $file->getClientMimeType() ?: 'application/pdf',
            size: $file->getSize() ?: 0,
            hash: $this->hash,
        );
    }

    public function store(User $owner, UploadedFile $file, MaterialFileMetadata $metadata): MaterialFileMetadata
    {
        $this->calls[] = 'store';

        $extension = $metadata->extension !== '' ? $metadata->extension : 'bin';
        $path = $owner->id.'/fake-'.$metadata->hash.'.'.$extension;
        $this->files[$path] = $this->uploadedBytes($file);

        return $metadata->withPath($path);
    }

    public function exists(string $path): bool
    {
        $this->calls[] = 'exists';

        if ($path === '' || trim($path) === '') {
            return false;
        }

        return array_key_exists($path, $this->files);
    }

    public function read(string $path): string
    {
        $this->calls[] = 'read';

        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }

        if ($path === '' || trim($path) === '' || ! array_key_exists($path, $this->files)) {
            throw new RuntimeException('Material file does not exist.');
        }

        return $this->files[$path];
    }

    public function delete(string $path): void
    {
        $this->calls[] = 'delete';
        $this->deleted[] = $path;
        unset($this->files[$path]);
    }

    private function uploadedBytes(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        if (is_string($realPath) && $realPath !== '' && is_file($realPath)) {
            $contents = file_get_contents($realPath);

            if ($contents !== false) {
                return $contents;
            }
        }

        return $file->getContent();
    }
}
