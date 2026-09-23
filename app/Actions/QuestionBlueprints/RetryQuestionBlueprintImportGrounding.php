<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintImportGroundingStatus;
use App\Jobs\GroundQuestionBlueprintImport;
use App\Models\QuestionBlueprintImport;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RetryQuestionBlueprintImportGrounding
{
    public function __construct(private AssertImportGroundingEligibility $assertEligibility) {}

    public function handle(QuestionBlueprintImport $import, ?User $actor = null): void
    {
        $this->assertEligibility->handle($import, $actor);

        $import->refresh();

        if ($import->grounding_status === BlueprintImportGroundingStatus::READY) {
            throw new InvalidArgumentException(ProcessQuestionBlueprintImportGrounding::ERROR_ALREADY_READY);
        }

        if ($import->grounding_status !== BlueprintImportGroundingStatus::FAILED) {
            return;
        }

        $observedQueuedAt = $import->grounding_queued_at;

        if ($observedQueuedAt === null) {
            return;
        }

        $queuedAt = $this->nextQueuedAt($observedQueuedAt);
        $updated = QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->where('grounding_status', BlueprintImportGroundingStatus::FAILED)
            ->where('grounding_queued_at', $observedQueuedAt)
            ->update([
                'grounding_status' => BlueprintImportGroundingStatus::QUEUED,
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
        $this->dispatch($import);
    }

    private function dispatch(QuestionBlueprintImport $import): void
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
            Log::warning('Blueprint import grounding retry dispatch failed.', [
                'import_id' => $import->import_id,
                'exception' => $exception::class,
            ]);

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

    private function nextQueuedAt(CarbonInterface $previous): CarbonInterface
    {
        $proposed = now()->copy()->startOfSecond();
        $previousSecond = $previous->copy()->startOfSecond();

        if ($proposed->lessThanOrEqualTo($previousSecond)) {
            return $previousSecond->copy()->addSecond();
        }

        return $proposed;
    }
}
