<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Exceptions\Generations\AttemptBudgetExhaustedException;
use App\Models\AiGenerationAttempt;
use App\Services\AI\GeminiQuestionGenerationProvider;
use App\Support\Generations\ProviderAttemptBudget;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BeginGenerationAttempt
{
    use LocksGenerationExecution;

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
                $generation = $this->lockUserAndGeneration($generationId);
                $this->assertOwnedProcessing($generation, $executionToken);

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
            throw new AttemptBudgetExhaustedException(
                'The generation provider attempt identity is already used.',
                $generationId,
            );
        }
    }
}
