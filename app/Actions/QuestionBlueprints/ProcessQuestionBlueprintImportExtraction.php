<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintImportStatus;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Models\QuestionBlueprintImport;
use App\Services\Materials\Extraction\DocxExtractor;
use App\Services\QuestionBlueprints\BlueprintImportStorageService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessQuestionBlueprintImportExtraction
{
    public function __construct(
        private BlueprintImportStorageService $storageService,
        private DocxExtractor $extractor,
    ) {}

    public function handle(int $importId): void
    {
        $claimed = QuestionBlueprintImport::query()
            ->where('import_id', $importId)
            ->whereIn('status', [BlueprintImportStatus::PENDING, BlueprintImportStatus::PROCESSING])
            ->update([
                'status' => BlueprintImportStatus::PROCESSING,
                'claimed_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $import = QuestionBlueprintImport::query()->findOrFail($importId);

        try {
            $contents = $this->storageService->get($import->storage_path);

            if ($contents === null) {
                throw new UnrecoverableMaterialExtractionException('Source file not found in storage.');
            }

            if ($contents === '') {
                throw new UnrecoverableMaterialExtractionException('Source file is empty.');
            }

            $extractedText = $this->extractor->extract($contents);

            if (trim($extractedText) === '') {
                throw new UnrecoverableMaterialExtractionException('Extracted text is empty.');
            }

            $import->update([
                'status' => BlueprintImportStatus::EXTRACTED,
                'extracted_text' => $extractedText,
                'completed_at' => now(),
            ]);

            $this->cleanupSource($import);
        } catch (UnrecoverableMaterialExtractionException $e) {
            $this->markFailed($import, 'unrecoverable_extraction', $e->getMessage());
        }
    }

    public function markFailedIfProcessing(int $importId): void
    {
        $import = QuestionBlueprintImport::query()
            ->where('import_id', $importId)
            ->where('status', BlueprintImportStatus::PROCESSING)
            ->first();

        if ($import !== null) {
            $this->markFailed($import, 'unexpected_error', 'Gagal memproses file kisi-kisi.');
        }
    }

    private function cleanupSource(QuestionBlueprintImport $import): void
    {
        if ($import->storage_path === null || $import->storage_path === '') {
            return;
        }

        try {
            $deleted = $this->storageService->delete($import->storage_path);
            if ($deleted) {
                $import->update(['storage_path' => null]);
            }
        } catch (Throwable $e) {
            Log::warning('Failed to cleanup blueprint import source file.', [
                'import_id' => $import->import_id,
                'path' => $import->storage_path,
                'exception' => $e::class,
            ]);
        }
    }

    private function markFailed(QuestionBlueprintImport $import, string $code, string $message): void
    {
        $import->update([
            'status' => BlueprintImportStatus::FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => now(),
        ]);

        $this->cleanupSource($import);
    }
}
