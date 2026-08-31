<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\Generations\GenerationClaimResult;
use App\Enums\GenerationStatus;
use Illuminate\Support\Facades\DB;

class ClaimGenerationExecution
{
    use LocksGenerationExecution;

    public function handle(int $generationId, string $executionToken): GenerationClaimResult
    {
        return DB::transaction(function () use ($generationId, $executionToken): GenerationClaimResult {
            $generation = $this->lockUserAndGeneration($generationId);

            if (in_array($generation->generation_status, [
                GenerationStatus::COMPLETED,
                GenerationStatus::FAILED,
                GenerationStatus::CANCELLED,
            ], true)) {
                return new GenerationClaimResult(false, 'terminal');
            }

            if ($generation->generation_status === GenerationStatus::QUEUED) {
                $generation->generation_status = GenerationStatus::PROCESSING;
                $generation->execution_token = $executionToken;
                $generation->started_at ??= now();
                $generation->save();

                return new GenerationClaimResult(true, 'claimed');
            }

            if ($generation->generation_status === GenerationStatus::PROCESSING) {
                if ((string) $generation->execution_token === $executionToken) {
                    return new GenerationClaimResult(true, 'resumed');
                }

                return new GenerationClaimResult(false, 'duplicate');
            }

            return new GenerationClaimResult(false, 'integrity');
        });
    }
}
