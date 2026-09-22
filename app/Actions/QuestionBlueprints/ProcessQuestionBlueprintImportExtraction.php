<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\ImportStructuredDocument;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Jobs\InterpretQuestionBlueprintImport;
use App\Models\QuestionBlueprintImport;
use App\Services\AI\BlueprintImportInterpretationPromptBuilder;
use App\Services\Materials\Extraction\DocxExtractor;
use App\Services\QuestionBlueprints\BlueprintImportDocxStructureExtractor;
use App\Services\QuestionBlueprints\BlueprintImportStorageService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessQuestionBlueprintImportExtraction
{
    public function __construct(
        private BlueprintImportStorageService $storageService,
        private BlueprintImportDocxStructureExtractor $structureExtractor,
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
            $this->redispatchUnclaimedQueuedInterpretation($importId);

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

            $structure = $this->structureExtractor->extract($contents);
            $extractedText = $this->extractor->extract($contents);

            if (trim($extractedText) === '') {
                throw new UnrecoverableMaterialExtractionException('Extracted text is empty.');
            }

            $import->update([
                'status' => BlueprintImportStatus::EXTRACTED,
                'extracted_text' => $extractedText,
                'structured_document' => $structure->toArray(),
                'structure_schema_version' => (string) config(
                    'question_blueprint.import_structure_schema_version',
                    ImportStructuredDocument::SCHEMA_VERSION,
                ),
                'completed_at' => now(),
                'interpretation_status' => BlueprintImportInterpretationStatus::QUEUED,
                'interpretation_prompt_version' => (string) config(
                    'question_blueprint.import_interpretation_prompt_version',
                    BlueprintImportInterpretationPromptBuilder::V1,
                ),
                'interpretation_queued_at' => now(),
                'interpretation_claimed_at' => null,
                'interpretation_completed_at' => null,
                'interpretation_result' => null,
                'interpretation_error_code' => null,
                'interpretation_error_message' => null,
            ]);

            $this->cleanupSource($import);
            $import->refresh();
            $this->dispatchInterpretation($import);
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

    private function redispatchUnclaimedQueuedInterpretation(int $importId): void
    {
        $import = QuestionBlueprintImport::query()
            ->where('import_id', $importId)
            ->where('status', BlueprintImportStatus::EXTRACTED)
            ->where('interpretation_status', BlueprintImportInterpretationStatus::QUEUED)
            ->whereNull('interpretation_claimed_at')
            ->first();

        if ($import === null) {
            return;
        }

        $this->dispatchInterpretation($import);
    }

    private function dispatchInterpretation(QuestionBlueprintImport $import): void
    {
        if ($import->interpretation_queued_at === null) {
            return;
        }

        try {
            InterpretQuestionBlueprintImport::dispatch(
                $import->import_id,
                $import->interpretation_queued_at->toIso8601String(),
            );
        } catch (Throwable $exception) {
            Log::warning('Blueprint import interpretation job dispatch failed.', [
                'import_id' => $import->import_id,
                'exception' => $exception::class,
            ]);

            QuestionBlueprintImport::query()
                ->where('import_id', $import->import_id)
                ->where('interpretation_status', BlueprintImportInterpretationStatus::QUEUED)
                ->where('interpretation_queued_at', $import->interpretation_queued_at)
                ->update([
                    'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
                    'interpretation_error_code' => ProcessQuestionBlueprintImportInterpretation::ERROR_QUEUE_DISPATCH_FAILED,
                    'interpretation_error_message' => app(ProcessQuestionBlueprintImportInterpretation::class)
                        ->publicMessage(ProcessQuestionBlueprintImportInterpretation::ERROR_QUEUE_DISPATCH_FAILED),
                    'interpretation_completed_at' => now(),
                ]);
        }
    }
}
