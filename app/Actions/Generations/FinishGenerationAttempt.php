<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Actions\GenerationRuns\AssertRunChildLiveAuthority;
use App\Actions\GenerationRuns\LocksGenerationRun;
use App\Data\Generations\ProviderAttemptMetadata;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use Illuminate\Support\Facades\DB;

class FinishGenerationAttempt
{
    use LocksGenerationExecution;
    use LocksGenerationRun;

    public function __construct(private AssertRunChildLiveAuthority $assertRunAuthority) {}

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
            $generation = $this->lockFinishGeneration($generationId, $executionToken);

            $attempt = AiGenerationAttempt::query()
                ->whereKey($attemptId)
                ->where('generation_id', $generation->generation_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($generation->generation_run_id !== null
                && $attempt->status !== GenerationAttemptStatus::STARTED) {
                throw new StaleGenerationExecutionException(
                    'This generation execution no longer owns the generation.',
                    $generationId,
                    $executionToken,
                );
            }

            if ($generation->generation_run_id !== null
                && ($status === GenerationAttemptStatus::SUCCEEDED || $accepted !== null)) {
                $this->assertRunChildSuccessAuthority($generation, $executionToken);
            }

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

    private function lockFinishGeneration(int $generationId, string $executionToken): AiGeneration
    {
        $runId = AiGeneration::query()->whereKey($generationId)->value('generation_run_id');

        if ($runId !== null) {
            $graph = $this->lockCanonicalRunPersistenceGraph((int) $runId);
            $generation = $graph['children']->first(
                fn (AiGeneration $candidate): bool => (int) $candidate->generation_id === $generationId,
            );

            if ($generation === null) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    generationId: $generationId,
                );
            }

            $this->assertRunAuthority->handle(
                $graph,
                $generation,
                $executionToken,
                requireProcessing: true,
                requireUnexpired: true,
                requireFingerprintsAndSpans: false,
                requireTopology: false,
            );

            return $generation;
        }

        $generation = $this->lockUserAndGeneration($generationId);
        $this->assertOwnedProcessing($generation, $executionToken);

        return $generation;
    }

    private function assertRunChildSuccessAuthority(AiGeneration $generation, string $executionToken): void
    {
        $graph = $this->lockCanonicalRunPersistenceGraph((int) $generation->generation_run_id);
        $locked = $graph['children']->first(
            fn (AiGeneration $candidate): bool => (int) $candidate->generation_id === (int) $generation->generation_id,
        );

        if ($locked === null) {
            throw new StaleGenerationExecutionException(
                'This generation execution no longer owns the generation.',
                (int) $generation->generation_id,
                $executionToken,
            );
        }

        $this->assertRunAuthority->handle(
            $graph,
            $locked,
            $executionToken,
            requireProcessing: true,
            requireUnexpired: true,
            requireFingerprintsAndSpans: true,
            requireTopology: true,
        );
    }
}
