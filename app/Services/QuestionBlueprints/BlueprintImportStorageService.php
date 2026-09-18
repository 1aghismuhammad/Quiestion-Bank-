<?php

declare(strict_types=1);

namespace App\Services\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportFileMetadata;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlueprintImportStorageService
{
    public const DISK_NAME = 'blueprint-imports';

    public function inspect(UploadedFile $file): BlueprintImportFileMetadata
    {
        return new BlueprintImportFileMetadata(
            originalName: $file->getClientOriginalName(),
            extension: $file->getClientOriginalExtension(),
            mimeType: $file->getMimeType() ?? 'application/octet-stream',
            size: $file->getSize(),
            hash: hash_file('sha256', $file->getRealPath()),
        );
    }

    public function store(User $user, UploadedFile $file, BlueprintImportFileMetadata $metadata): BlueprintImportFileMetadata
    {
        $uuid = Str::uuid()->toString();
        $extension = strtolower($metadata->extension) ?: 'docx';
        $path = $user->id . '/' . $uuid . '.' . $extension;

        Storage::disk(self::DISK_NAME)->putFileAs(
            $user->id,
            $file,
            $uuid . '.' . $extension
        );

        return $metadata->withPath($path);
    }

    public function get(string $path): ?string
    {
        if (!Storage::disk(self::DISK_NAME)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK_NAME)->get($path);
    }

    public function delete(string $path): bool
    {
        return Storage::disk(self::DISK_NAME)->delete($path);
    }
}
