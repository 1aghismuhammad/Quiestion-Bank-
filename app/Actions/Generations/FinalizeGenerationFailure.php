<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Models\AiGeneration;
use Illuminate\Support\Facades\DB;

class FinalizeGenerationFailure
{
    use LocksGenerationExecution;
    use LocksStoredGenerationUsage;

    public function __construct(private ReleaseGenerationCredit $release) {}

    public function handle(int $generationId, string $executionToken, GenerationErrorCode $errorCode): AiGeneration
    {
        return DB::transaction(function () use ($generationId, $executionToken, $errorCode): AiGeneration {
            $generation = $this->lockUserAndGeneration($generationId);
            $usage = $this->lockStoredReservation($generation);

            if (
                $generation->generation_status === GenerationStatus::FAILED
                && $usage->status === UsageStatus::RELEASED
            ) {
                return $generation;
            }

            if ($generation->generation_status === GenerationStatus::COMPLETED) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    (int) $generation->user_id,
                    (int) $generation->generation_id,
                    $usage->usage_id,
                );
            }

            if (
                (string) $generation->execution_token !== $executionToken
                && ! (
                    $generation->generation_status === GenerationStatus::QUEUED
                    && $generation->execution_token === null
                )
            ) {
                throw new StaleGenerationExecutionException(
                    'This generation execution no longer owns the generation.',
                    (int) $generation->generation_id,
                    $executionToken,
                );
            }

            if (! in_array($generation->generation_status, [
                GenerationStatus::PROCESSING,
                GenerationStatus::QUEUED,
            ], true)) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    (int) $generation->user_id,
                    (int) $generation->generation_id,
                    $usage->usage_id,
                );
            }

            $generation->error_code = $errorCode->value;
            $generation->error_message = $errorCode->userMessage();
            $generation->failed_at = now();
            $generation->completed_at = null;
            $generation->generation_status = GenerationStatus::FAILED;
            $generation->save();

            $this->release->handle($generation);

            return $generation->refresh();
        });
    }
}
