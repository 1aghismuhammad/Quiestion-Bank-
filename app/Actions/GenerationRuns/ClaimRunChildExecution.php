<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Data\Generations\GenerationClaimResult;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Support\Generations\ResolvesGenerationRunStaleCutoff;
use Illuminate\Support\Facades\DB;

class ClaimRunChildExecution
{
    use LocksGenerationRun;
    use ResolvesGenerationRunStaleCutoff;

    public function handle(int $generationId, string $executionToken): GenerationClaimResult
    {
        return DB::transaction(function () use ($generationId, $executionToken): GenerationClaimResult {
            $child = AiGeneration::query()->whereKey($generationId)->first();

            if ($child === null || $child->generation_run_id === null) {
                return new GenerationClaimResult(false, 'integrity');
            }

            $graph = $this->lockCanonicalRunGraph((int) $child->generation_run_id);
            $run = $graph['run'];
            $children = $graph['children'];

            $locked = $children->first(
                fn (AiGeneration $candidate): bool => (int) $candidate->generation_id === $generationId,
            );

            if ($locked === null) {
                return new GenerationClaimResult(false, 'integrity');
            }

            if ($run->status->isTerminal() || in_array($locked->generation_status, [
                GenerationStatus::COMPLETED,
                GenerationStatus::FAILED,
                GenerationStatus::CANCELLED,
            ], true)) {
                return new GenerationClaimResult(false, 'terminal');
            }

            $processingOther = $children->first(
                fn (AiGeneration $candidate): bool => $candidate->generation_status === GenerationStatus::PROCESSING
                    && (int) $candidate->generation_id !== $generationId,
            );

            if ($processingOther !== null) {
                return new GenerationClaimResult(false, 'duplicate');
            }

            if ($locked->generation_status === GenerationStatus::QUEUED) {
                if ($this->runChildAuthorityExpired($locked)) {
                    return new GenerationClaimResult(false, 'stale');
                }

                $expected = $children
                    ->filter(fn (AiGeneration $candidate): bool => $candidate->generation_status === GenerationStatus::QUEUED)
                    ->sortBy(fn (AiGeneration $candidate): int => (int) $candidate->child_index)
                    ->first();

                if ($expected === null || (int) $expected->generation_id !== $generationId) {
                    return new GenerationClaimResult(false, 'duplicate');
                }

                $persistedToken = (string) ($locked->execution_token ?? '');

                if ($persistedToken === '' || $persistedToken !== $executionToken) {
                    return new GenerationClaimResult(false, 'duplicate');
                }

                if ($run->status === GenerationRunStatus::Queued) {
                    $run->status = GenerationRunStatus::Processing;
                    $run->started_at ??= now();
                    $run->save();
                }

                $locked->generation_status = GenerationStatus::PROCESSING;
                $locked->started_at ??= now();
                $locked->save();

                return new GenerationClaimResult(true, 'claimed');
            }

            if ($locked->generation_status === GenerationStatus::PROCESSING) {
                if ((string) $locked->execution_token !== $executionToken) {
                    return new GenerationClaimResult(false, 'duplicate');
                }

                if ($this->runChildAuthorityExpired($locked)) {
                    return new GenerationClaimResult(false, 'stale');
                }

                $started = AiGenerationAttempt::query()
                    ->where('generation_id', $locked->generation_id)
                    ->where('status', GenerationAttemptStatus::STARTED)
                    ->lockForUpdate()
                    ->exists();

                if ($started) {
                    return new GenerationClaimResult(false, 'duplicate');
                }

                return new GenerationClaimResult(true, 'resumed');
            }

            return new GenerationClaimResult(false, 'integrity');
        });
    }
}
