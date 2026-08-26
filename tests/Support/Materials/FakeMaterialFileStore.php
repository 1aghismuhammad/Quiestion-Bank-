<?php

declare(strict_types=1);

namespace Tests\Support\Materials;

use App\Contracts\Materials\MaterialFileStore;
use App\Data\Materials\MaterialFileMetadata;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class FakeMaterialFileStore implements MaterialFileStore
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $deleted = [];

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

        return $metadata->withPath('materials/'.$owner->id.'/fake-'.$metadata->hash.'.'.$extension);
    }

    public function delete(string $path): void
    {
        $this->calls[] = 'delete';
        $this->deleted[] = $path;
    }
}
