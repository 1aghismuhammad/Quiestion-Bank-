<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Models\AiGeneration;
use App\Support\Generations\ResolvesGenerationRunStaleCutoff;
use Illuminate\Support\Facades\DB;

class FinalizeRunChildFailure
{
    use LocksGenerationRun;
    use ResolvesGenerationRunStaleCutoff;

    public function __construct(
        private TerminalizeGenerationRun $terminalize,
    ) {}

    public function handle(int $generationId, string $executionToken, GenerationErrorCode $errorCode): AiGeneration
    {
        return DB::transaction(function () use ($generationId, $executionToken, $errorCode): AiGeneration {
            $child = AiGeneration::query()->whereKey($generationId)->firstOrFail();
            $graph = $this->lockCanonicalRunGraph((int) $child->generation_run_id);
            $run = $graph['run'];
            $children = $graph['children'];
            $usage = $graph['usage'];
            $attempts = $graph['attempts'];
            $items = $graph['items'];

            $locked = $children->first(
                fn (AiGeneration $candidate): bool => (int) $candidate->generation_id === $generationId,
            );

            if ($locked === null) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    generationId: $generationId,
                );
            }

            if ($run->status === GenerationRunStatus::Failed
                && $locked->generation_status === GenerationStatus::FAILED
                && $usage->status === UsageStatus::RELEASED) {
                $this->terminalize->closeStartedAttempts($attempts, $errorCode);

                return $locked;
            }

            if ($run->status->isTerminal()) {
                throw new StaleGenerationExecutionException(
                    'This generation execution no longer owns the generation.',
                    (int) $locked->generation_id,
                    $executionToken,
                );
            }

            if (
                (string) $locked->execution_token !== $executionToken
                && ! (
                    $locked->generation_status === GenerationStatus::QUEUED
                    && $locked->execution_token === null
                )
            ) {
                throw new StaleGenerationExecutionException(
                    'This generation execution no longer owns the generation.',
                    (int) $locked->generation_id,
                    $executionToken,
                );
            }

            if (! in_array($locked->generation_status, [
                GenerationStatus::PROCESSING,
                GenerationStatus::QUEUED,
            ], true)) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    (int) $locked->user_id,
                    (int) $locked->generation_id,
                );
            }

            if ($this->runChildAuthorityExpired($locked)) {
                throw new StaleGenerationExecutionException(
                    'This generation execution no longer owns the generation.',
                    (int) $locked->generation_id,
                    $executionToken,
                );
            }

            $locked->error_code = $errorCode->value;
            $locked->error_message = $errorCode->userMessage();
            $locked->failed_at = now();
            $locked->completed_at = null;
            $locked->generation_status = GenerationStatus::FAILED;
            $locked->save();

            $this->terminalize->apply($run->refresh(), $children, $usage, $items, $attempts);

            return $locked->refresh();
        });
    }
}
