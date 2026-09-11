<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Actions\GenerationRuns\LocksGenerationRun;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Exceptions\Generations\AttemptBudgetExhaustedException;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Services\AI\GeminiQuestionGenerationProvider;
use App\Support\Generations\ProviderAttemptBudget;
use App\Support\Generations\ResolvesGenerationRunStaleCutoff;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BeginGenerationAttempt
{
    use LocksGenerationExecution;
    use LocksGenerationRun;
    use ResolvesGenerationRunStaleCutoff;

    public function handle(
        int $generationId,
        string $executionToken,
        GenerationAttemptPurpose $purpose,
        int $requestedCount,
        string $model,
        string $promptVersion,
    ): AiGenerationAttempt {
        try {
            return DB::transaction(function () use (
                $generationId,
                $executionToken,
                $purpose,
                $requestedCount,
                $model,
                $promptVersion,
            ): AiGenerationAttempt {
                $generation = $this->lockAttemptGeneration($generationId, $executionToken);

                $startedCount = AiGenerationAttempt::query()
                    ->where('generation_id', $generation->generation_id)
                    ->count();

                $maxAttempts = ProviderAttemptBudget::max();
                $nextAttempt = $startedCount + 1;

                if ($nextAttempt > $maxAttempts) {
                    throw new AttemptBudgetExhaustedException(
                        'The generation provider attempt budget is exhausted.',
                        (int) $generation->generation_id,
                    );
                }

                $attempt = AiGenerationAttempt::query()->create([
                    'generation_id' => $generation->generation_id,
                    'attempt_number' => $nextAttempt,
                    'provider' => GeminiQuestionGenerationProvider::PROVIDER_NAME,
                    'model' => $model,
                    'purpose' => $purpose,
                    'prompt_version' => $promptVersion,
                    'requested_count' => $requestedCount,
                    'accepted_count' => 0,
                    'status' => GenerationAttemptStatus::STARTED,
                    'started_at' => now(),
                    'finished_at' => null,
                ]);

                $generation->attempt_number = $nextAttempt;
                $generation->save();

                return $attempt;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (AiGeneration::query()->whereKey($generationId)->value('generation_run_id') !== null) {
                throw new StaleGenerationExecutionException(
                    'This generation execution no longer owns the generation.',
                    $generationId,
                    $executionToken,
                );
            }

            throw new AttemptBudgetExhaustedException(
                'The generation provider attempt identity is already used.',
                $generationId,
            );
        }
    }

    private function lockAttemptGeneration(int $generationId, string $executionToken): AiGeneration
    {
        $runId = AiGeneration::query()->whereKey($generationId)->value('generation_run_id');

        if ($runId !== null) {
            $graph = $this->lockCanonicalRunGraph((int) $runId);
            $generation = $graph['children']->first(
                fn (AiGeneration $candidate): bool => (int) $candidate->generation_id === $generationId,
            );

            if ($generation === null) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    generationId: $generationId,
                );
            }

            $this->assertOwnedProcessing($generation, $executionToken);

            if ($this->runChildAuthorityExpired($generation)) {
                throw new StaleGenerationExecutionException(
                    'This generation execution no longer owns the generation.',
                    (int) $generation->generation_id,
                    $executionToken,
                );
            }

            $hasStartedAttempt = $graph['attempts']->contains(
                fn (AiGenerationAttempt $attempt): bool => (int) $attempt->generation_id === $generationId
                    && $attempt->status === GenerationAttemptStatus::STARTED,
            );

            if ($hasStartedAttempt) {
                throw new StaleGenerationExecutionException(
                    'This generation execution no longer owns the generation.',
                    (int) $generation->generation_id,
                    $executionToken,
                );
            }

            return $generation;
        }

        $generation = $this->lockUserAndGeneration($generationId);
        $this->assertOwnedProcessing($generation, $executionToken);

        return $generation;
    }
}
