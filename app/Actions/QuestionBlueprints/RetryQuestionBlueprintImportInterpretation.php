<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Jobs\InterpretQuestionBlueprintImport;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryQuestionBlueprintImportInterpretation
{
    public function handle(QuestionBlueprintImport $import, ?User $actor = null): void
    {
        if ($actor !== null) {
            $this->assertOwner($actor, $import);
        }

        if ($import->status !== BlueprintImportStatus::EXTRACTED
            || ! is_array($import->structured_document)
            || $import->structure_schema_version !== (string) config(
                'question_blueprint.import_structure_schema_version',
                'blueprint-import-structure-v1',
            )) {
            return;
        }

        if ($import->interpretation_status === BlueprintImportInterpretationStatus::QUEUED) {
            $this->dispatch($import);

            return;
        }

        if ($import->interpretation_status !== BlueprintImportInterpretationStatus::FAILED) {
            return;
        }

        $observedQueuedAt = $import->interpretation_queued_at;

        if ($observedQueuedAt === null) {
            return;
        }

        $queuedAt = $this->nextQueuedAt($observedQueuedAt);
        $updated = QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->where('interpretation_status', BlueprintImportInterpretationStatus::FAILED)
            ->where('interpretation_queued_at', $observedQueuedAt)
            ->update([
                'interpretation_status' => BlueprintImportInterpretationStatus::QUEUED,
                'interpretation_queued_at' => $queuedAt,
                'interpretation_claimed_at' => null,
                'interpretation_completed_at' => null,
                'interpretation_result' => null,
                'interpretation_error_code' => null,
                'interpretation_error_message' => null,
            ]);

        if ($updated !== 1) {
            return;
        }

        $import->refresh();
        $this->dispatch($import);
    }

    private function dispatch(QuestionBlueprintImport $import): void
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
            Log::warning('Blueprint import interpretation retry dispatch failed.', [
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

    private function nextQueuedAt(?CarbonInterface $previous): CarbonInterface
    {
        $proposed = now()->copy()->startOfSecond();

        if ($previous === null) {
            return $proposed;
        }

        $previousSecond = $previous->copy()->startOfSecond();

        if ($proposed->lessThanOrEqualTo($previousSecond)) {
            return $previousSecond->copy()->addSecond();
        }

        return $proposed;
    }

    private function assertOwner(User $actor, QuestionBlueprintImport $import): void
    {
        $materialOwnerId = $import->material->user_id;

        if ($import->user_id !== $actor->id || $materialOwnerId !== $actor->id) {
            throw new AuthorizationException;
        }
    }
}
