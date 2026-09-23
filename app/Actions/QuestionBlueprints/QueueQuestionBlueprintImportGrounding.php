<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintImportGroundingStatus;
use App\Jobs\GroundQuestionBlueprintImport;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use App\Services\AI\BlueprintImportGroundingPromptBuilder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class QueueQuestionBlueprintImportGrounding
{
    public function __construct(private AssertImportGroundingEligibility $assertEligibility) {}

    public function handle(QuestionBlueprintImport $import, ?User $actor = null): void
    {
        $this->assertEligibility->handle($import, $actor);

        $import->refresh();

        if ($import->grounding_status === BlueprintImportGroundingStatus::READY) {
            throw new InvalidArgumentException(ProcessQuestionBlueprintImportGrounding::ERROR_ALREADY_READY);
        }

        if ($import->grounding_status === BlueprintImportGroundingStatus::PROCESSING) {
            throw new InvalidArgumentException(ProcessQuestionBlueprintImportGrounding::ERROR_IN_FLIGHT);
        }

        if ($import->grounding_status === BlueprintImportGroundingStatus::FAILED) {
            throw new InvalidArgumentException(ProcessQuestionBlueprintImportGrounding::ERROR_USE_RETRY);
        }

        if ($import->grounding_status === BlueprintImportGroundingStatus::QUEUED) {
            $this->dispatch($import, wasNull: false);

            return;
        }

        if ($import->grounding_status !== null) {
            throw new InvalidArgumentException(ProcessQuestionBlueprintImportGrounding::ERROR_INPUT_NOT_ELIGIBLE);
        }

        $queuedAt = now()->copy()->startOfSecond();
        $promptVersion = (string) config(
            'question_blueprint.import_grounding_prompt_version',
            BlueprintImportGroundingPromptBuilder::V1,
        );

        $updated = QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->whereNull('grounding_status')
            ->update([
                'grounding_status' => BlueprintImportGroundingStatus::QUEUED,
                'grounding_prompt_version' => $promptVersion,
                'grounding_queued_at' => $queuedAt,
                'grounding_claimed_at' => null,
                'grounding_completed_at' => null,
                'grounding_result' => null,
                'grounding_error_code' => null,
                'grounding_error_message' => null,
            ]);

        if ($updated !== 1) {
            return;
        }

        $import->refresh();
        $this->dispatch($import, wasNull: true);
    }

    private function dispatch(QuestionBlueprintImport $import, bool $wasNull): void
    {
        if ($import->grounding_queued_at === null) {
            return;
        }

        try {
            GroundQuestionBlueprintImport::dispatch(
                $import->import_id,
                $import->grounding_queued_at->toIso8601String(),
            );
        } catch (Throwable $exception) {
            Log::warning('Blueprint import grounding queue dispatch failed.', [
                'import_id' => $import->import_id,
                'exception' => $exception::class,
            ]);

            if ($wasNull) {
                QuestionBlueprintImport::query()
                    ->where('import_id', $import->import_id)
                    ->where('grounding_status', BlueprintImportGroundingStatus::QUEUED)
                    ->where('grounding_queued_at', $import->grounding_queued_at)
                    ->update([
                        'grounding_status' => null,
                        'grounding_prompt_version' => null,
                        'grounding_queued_at' => null,
                        'grounding_claimed_at' => null,
                        'grounding_completed_at' => null,
                        'grounding_result' => null,
                        'grounding_error_code' => null,
                        'grounding_error_message' => null,
                    ]);

                return;
            }

            QuestionBlueprintImport::query()
                ->where('import_id', $import->import_id)
                ->where('grounding_status', BlueprintImportGroundingStatus::QUEUED)
                ->where('grounding_queued_at', $import->grounding_queued_at)
                ->update([
                    'grounding_status' => BlueprintImportGroundingStatus::FAILED,
                    'grounding_error_code' => ProcessQuestionBlueprintImportGrounding::ERROR_QUEUE_DISPATCH_FAILED,
                    'grounding_error_message' => app(ProcessQuestionBlueprintImportGrounding::class)
                        ->publicMessage(ProcessQuestionBlueprintImportGrounding::ERROR_QUEUE_DISPATCH_FAILED),
                    'grounding_completed_at' => now(),
                ]);
        }
    }
}
