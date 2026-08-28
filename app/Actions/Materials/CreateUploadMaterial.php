<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Contracts\Materials\MaterialFileStore;
use App\Data\Materials\MaterialFileMetadata;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Jobs\ExtractMaterialContent;
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
    public function __construct(
        private MaterialFileStore $fileStore,
        private GuardUploadStorageQuota $guard,
        private ResolveUserEntitlement $resolveEntitlement,
    ) {}

    public function handle(User $user, string $title, UploadedFile $file): Material
    {
        $metadata = $this->fileStore->inspect($file);

        if ($this->ownerAlreadyHasHash($user, $metadata->hash)) {
            throw ValidationException::withMessages([
                'file' => 'File yang sama sudah diunggah.',
            ]);
        }

        $stored = null;

        try {
            $material = DB::transaction(function () use ($user, $title, $file, $metadata, &$stored): Material {
                $owner = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->ownerAlreadyHasHash($owner, $metadata->hash)) {
                    throw ValidationException::withMessages([
                        'file' => 'File yang sama sudah diunggah.',
                    ]);
                }

                $entitlement = $this->resolveEntitlement->handle($owner);
                $this->guard->handle($owner, $metadata->size, $entitlement);

                $stored = $this->fileStore->store($owner, $file, $metadata);

                return $owner->materials()->create([
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

        try {
            ExtractMaterialContent::dispatch($material->material_id);
        } catch (Throwable $exception) {
            Log::warning('Material extraction job dispatch failed.', [
                'material_id' => $material->material_id,
                'exception' => $exception::class,
            ]);
        }

        return $material;
    }

    private function ownerAlreadyHasHash(User $user, string $hash): bool
    {
        return Material::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->where('file_hash', $hash)
            ->exists();
    }

    private function compensate(?MaterialFileMetadata $stored): void
    {
        if ($stored === null || $stored->path === null || $stored->path === '') {
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
