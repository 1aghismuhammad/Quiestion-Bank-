<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Contracts\Materials\MaterialFileStore;
use App\Data\Materials\MaterialFileMetadata;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateUploadMaterial
{
    public function __construct(private MaterialFileStore $fileStore) {}

    public function handle(User $user, string $title, UploadedFile $file): Material
    {
        $metadata = $this->fileStore->inspect($file);

        if ($this->ownerAlreadyHasHash($user, $metadata->hash)) {
            throw ValidationException::withMessages([
                'file' => 'File yang sama sudah diunggah.',
            ]);
        }

        $stored = $this->fileStore->store($user, $file, $metadata);

        try {
            return DB::transaction(function () use ($user, $title, $stored): Material {
                return $user->materials()->create([
                    'title' => $title,
                    'source_type' => SourceType::UPLOAD,
                    'file_name' => $stored->originalName,
                    'file_path' => $stored->path,
                    'file_size' => $stored->size,
                    'file_hash' => $stored->hash,
                    'mime_type' => $stored->mimeType,
                    'content' => null,
                    'extraction_status' => ExtractionStatus::PENDING,
                    'status' => MaterialStatus::DRAFT,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->compensate($stored);

            throw ValidationException::withMessages([
                'file' => 'File yang sama sudah diunggah.',
            ]);
        } catch (Throwable $exception) {
            $this->compensate($stored);

            throw $exception;
        }
    }

    private function ownerAlreadyHasHash(User $user, string $hash): bool
    {
        return Material::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->where('file_hash', $hash)
            ->exists();
    }

    private function compensate(MaterialFileMetadata $stored): void
    {
        if ($stored->path === null || $stored->path === '') {
            return;
        }

        try {
            $this->fileStore->delete($stored->path);
        } catch (Throwable $cleanupException) {
            Log::warning('Material upload file cleanup failed.', [
                'path' => $stored->path,
                'exception' => $cleanupException::class,
            ]);
        }
    }
}
