<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\Generations\ProviderAttemptMetadata;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Models\AiGenerationAttempt;
use Illuminate\Support\Facades\DB;

class FinishGenerationAttempt
{
    use LocksGenerationExecution;

    public function handle(
        int $generationId,
        string $executionToken,
        int $attemptId,
        GenerationAttemptStatus $status,
        int $acceptedCount,
        ?ProviderAttemptMetadata $metadata = null,
        ?GenerationErrorCode $errorCode = null,
        ?ValidatedMcqSet $accepted = null,
    ): AiGenerationAttempt {
        return DB::transaction(function () use (
            $generationId,
            $executionToken,
            $attemptId,
            $status,
            $acceptedCount,
            $metadata,
            $errorCode,
            $accepted,
        ): AiGenerationAttempt {
            $generation = $this->lockUserAndGeneration($generationId);
            $this->assertOwnedProcessing($generation, $executionToken);

            $attempt = AiGenerationAttempt::query()
                ->whereKey($attemptId)
                ->where('generation_id', $generation->generation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $attempt->status = $status;
            $attempt->accepted_count = $acceptedCount;
            $attempt->finished_at = now();

            if ($metadata !== null) {
                $attempt->provider = $metadata->provider;
                $attempt->model = $metadata->model;
                $attempt->input_tokens = $metadata->inputTokens;
                $attempt->output_tokens = $metadata->outputTokens;
                $attempt->total_tokens = $metadata->totalTokens;
                $attempt->latency_ms = $metadata->latencyMs;
                $attempt->finish_reason = $metadata->finishReason;
            }

            $attempt->safe_error_code = $errorCode?->value;
            $attempt->save();

            if ($accepted !== null) {
                $generation->result_json = $accepted->toArray();
                $generation->save();
            }

            return $attempt->refresh();
        });
    }
}
