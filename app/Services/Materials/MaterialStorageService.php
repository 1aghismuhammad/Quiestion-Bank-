<?php

declare(strict_types=1);

namespace App\Services\Materials;

use App\Contracts\Materials\MaterialFileStore;
use App\Data\Materials\MaterialFileMetadata;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MaterialStorageService implements MaterialFileStore
{
    private const DISK = 'materials';

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'txt'];

    public function inspect(UploadedFile $file): MaterialFileMetadata
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Material file extension is not allowed.');
        }

        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '' || ! is_file($realPath)) {
            throw new RuntimeException('Material file path is not available.');
        }

        $hash = hash_file('sha256', $realPath);

        if ($hash === false) {
            throw new RuntimeException('Material file hash could not be calculated.');
        }

        $mimeType = $file->getMimeType();

        if (! is_string($mimeType) || $mimeType === '') {
            throw new RuntimeException('Material file MIME type could not be detected.');
        }

        $size = $file->getSize();

        if (! is_int($size) || $size < 1) {
            throw new RuntimeException('Material file size is invalid.');
        }

        return new MaterialFileMetadata(
            originalName: basename($file->getClientOriginalName()),
            extension: $extension,
            mimeType: $mimeType,
            size: $size,
            hash: $hash,
        );
    }

    public function store(User $owner, UploadedFile $file, MaterialFileMetadata $metadata): MaterialFileMetadata
    {
        $filename = (string) Str::uuid().'.'.$metadata->extension;
        $directory = (string) $owner->id;
        $path = $directory.'/'.$filename;

        Storage::disk(self::DISK)->putFileAs($directory, $file, $filename);

        return $metadata->withPath($path);
    }

    public function delete(string $path): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
