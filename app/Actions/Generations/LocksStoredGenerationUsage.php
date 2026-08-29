<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\User;

trait LocksStoredGenerationUsage
{
    private function lockStoredReservation(AiGeneration $generation): AiUsageLog
    {
        $generationId = (int) $generation->getKey();

        $ownerId = AiGeneration::query()->whereKey($generationId)->value('user_id');

        if ($ownerId === null) {
            throw new InvalidGenerationUsageException(
                'The generation usage cannot be finalized.',
                generationId: $generationId,
            );
        }

        User::query()->whereKey($ownerId)->lockForUpdate()->firstOrFail();

        $lockedGeneration = AiGeneration::query()
            ->whereKey($generationId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $lockedGeneration->user_id !== (int) $ownerId) {
            throw new InvalidGenerationUsageException(
                'The generation usage cannot be finalized.',
                (int) $lockedGeneration->user_id,
                $generationId,
            );
        }

        $usage = AiUsageLog::query()
            ->where('generation_id', $lockedGeneration->generation_id)
            ->lockForUpdate()
            ->first();

        if ($usage === null) {
            throw new InvalidGenerationUsageException(
                'The generation usage cannot be finalized.',
                (int) $lockedGeneration->user_id,
                $generationId,
            );
        }

        if (
            (int) $usage->user_id !== (int) $lockedGeneration->user_id
            || (int) $usage->generation_id !== (int) $lockedGeneration->generation_id
        ) {
            throw new InvalidGenerationUsageException(
                'The generation usage cannot be finalized.',
                (int) $usage->user_id,
                $generationId,
                $usage->usage_id,
            );
        }

        return $usage;
    }
}
