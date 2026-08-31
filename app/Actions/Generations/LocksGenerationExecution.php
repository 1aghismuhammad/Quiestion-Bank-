<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Models\AiGeneration;
use App\Models\User;

trait LocksGenerationExecution
{
    private function lockUserAndGeneration(int $generationId): AiGeneration
    {
        $ownerId = AiGeneration::query()->whereKey($generationId)->value('user_id');

        if ($ownerId === null) {
            throw new InvalidGenerationUsageException(
                'The generation usage cannot be finalized.',
                generationId: $generationId,
            );
        }

        User::query()->whereKey($ownerId)->lockForUpdate()->firstOrFail();

        return AiGeneration::query()
            ->whereKey($generationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertOwnedProcessing(AiGeneration $generation, string $executionToken): void
    {
        if ($generation->generation_status !== GenerationStatus::PROCESSING) {
            throw new StaleGenerationExecutionException(
                'This generation execution no longer owns the generation.',
                (int) $generation->generation_id,
                $executionToken,
            );
        }

        if ((string) $generation->execution_token !== $executionToken) {
            throw new StaleGenerationExecutionException(
                'This generation execution no longer owns the generation.',
                (int) $generation->generation_id,
                $executionToken,
            );
        }
    }
}
