<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Actions\MaterialProfiles\AssertMaterialEligibleForProfileAnalysis;
use App\Data\QuestionBlueprints\BlueprintImportFileMetadata;
use App\Enums\BlueprintImportStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Jobs\ExtractQuestionBlueprintImport;
use App\Models\Material;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use App\Services\QuestionBlueprints\BlueprintImportStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateQuestionBlueprintImport
{
    use LocksQuestionBlueprintWorkflow;

    public const MAX_FILE_SIZE_KILOBYTES = 10 * 1024;

    public const ALLOWED_EXTENSIONS = ['docx'];

    public function __construct(
        private AssertMaterialEligibleForProfileAnalysis $assertEligible,
        private AssertReadyMatchingProfile $assertProfile,
        private BlueprintImportStorageService $storageService,
    ) {}

    public function handle(User $actor, Material $material, UploadedFile $file): QuestionBlueprintImport
    {
        $this->validateFile($file);

        $metadata = $this->storageService->inspect($file);

        if ($this->hasActiveDuplicate($actor, $material, $metadata->hash)) {
            throw ValidationException::withMessages([
                'file' => 'File kisi-kisi yang sama sedang diproses atau sudah ada.',
            ]);
        }

        $stored = null;

        try {
            $import = DB::transaction(function () use ($actor, $material, $file, $metadata, &$stored): QuestionBlueprintImport {
                $locked = $this->lockUserAndMaterial((int) $actor->id, (int) $material->material_id);

                try {
                    $this->assertEligible->handle($locked);
                } catch (MaterialProfileRejectedException) {
                    throw ValidationException::withMessages([
                        'material' => 'Materi tidak memenuhi syarat.',
                    ]);
                }

                $profile = $this->assertProfile->requireMatchingReady($locked);
                $this->lockProfileVersion((int) $profile->profile_version_id);

                if ($this->hasActiveDuplicate($actor, $material, $metadata->hash)) {
                    throw ValidationException::withMessages([
                        'file' => 'File kisi-kisi yang sama sedang diproses atau sudah ada.',
                    ]);
                }

                $fingerprint = $this->assertProfile->fingerprint($locked);

                $stored = $this->storageService->store($actor, $file, $metadata);
                $queuedAt = now();

                return QuestionBlueprintImport::query()->create([
                    'user_id' => $locked->user_id,
                    'material_id' => $locked->material_id,
                    'profile_version_id' => $profile->profile_version_id,
                    'status' => BlueprintImportStatus::PENDING,
                    'original_file_name' => $stored->originalName,
                    'storage_path' => $stored->path,
                    'file_size' => $stored->size,
                    'file_hash' => $stored->hash,
                    'mime_type' => $stored->mimeType,
                    'material_content_hash' => $fingerprint['material_content_hash'],
                    'material_file_hash' => $fingerprint['material_file_hash'],
                    'extractor_implementation' => $fingerprint['extractor_implementation'],
                    'queued_at' => $queuedAt,
                ]);
            });
        } catch (Throwable $exception) {
            $this->compensate($stored);
            throw $exception;
        }

        try {
            ExtractQuestionBlueprintImport::dispatch($import->import_id);
        } catch (Throwable $exception) {
            Log::warning('Blueprint import extraction job dispatch failed.', [
                'import_id' => $import->import_id,
                'exception' => $exception::class,
            ]);

            return $this->failUnstartedImport($import);
        }

        return $import;
    }

    private function validateFile(UploadedFile $file): void
    {
        if ($file->getSize() === 0) {
            throw ValidationException::withMessages(['file' => 'File tidak boleh kosong.']);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['file' => 'Ukuran file maksimal 10 MB.']);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'File harus berupa DOCX.']);
        }
    }

    private function hasActiveDuplicate(User $user, Material $material, string $hash): bool
    {
        return QuestionBlueprintImport::query()
            ->where('user_id', $user->id)
            ->where('material_id', $material->material_id)
            ->where('file_hash', $hash)
            ->whereIn('status', [
                BlueprintImportStatus::PENDING,
                BlueprintImportStatus::PROCESSING,
                BlueprintImportStatus::EXTRACTED,
            ])
            ->exists();
    }

    private function failUnstartedImport(QuestionBlueprintImport $import): QuestionBlueprintImport
    {
        $updated = QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->where('status', BlueprintImportStatus::PENDING)
            ->update([
                'status' => BlueprintImportStatus::FAILED,
                'error_code' => 'queue_dispatch_failed',
                'error_message' => 'Gagal mengantrekan proses file kisi-kisi.',
                'completed_at' => now(),
            ]);

        $import->refresh();

        if ($updated === 1) {
            $this->cleanupDispatchFailureSource($import);
        }

        return $import;
    }

    private function cleanupDispatchFailureSource(QuestionBlueprintImport $import): void
    {
        if ($import->storage_path === null || $import->storage_path === '') {
            return;
        }

        try {
            $deleted = $this->storageService->delete($import->storage_path);
            if ($deleted) {
                $import->update(['storage_path' => null]);

                return;
            }

            Log::warning('Blueprint import dispatch-failure source cleanup failed.', [
                'import_id' => $import->import_id,
                'reason' => 'delete_returned_false',
            ]);
        } catch (Throwable $cleanupException) {
            Log::warning('Blueprint import dispatch-failure source cleanup failed.', [
                'import_id' => $import->import_id,
                'exception' => $cleanupException::class,
            ]);
        }
    }

    private function compensate(?BlueprintImportFileMetadata $stored): void
    {
        if ($stored === null || $stored->path === null || $stored->path === '') {
            return;
        }

        try {
            $this->storageService->delete($stored->path);
        } catch (Throwable $cleanupException) {
            Log::warning('Blueprint import upload file cleanup failed.', [
                'path' => $stored->path,
                'exception' => $cleanupException::class,
            ]);
        }
    }
}
