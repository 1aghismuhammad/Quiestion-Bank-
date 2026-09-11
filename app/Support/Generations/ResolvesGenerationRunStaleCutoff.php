<?php

declare(strict_types=1);

namespace App\Support\Generations;

use App\Enums\GenerationStatus;
use App\Models\AiGeneration;
use Illuminate\Support\Carbon;

trait ResolvesGenerationRunStaleCutoff
{
    private function generationRunStaleSeconds(): int
    {
        return max(1800, (int) config('generation.stale_after_seconds', 1800));
    }

    private function generationRunStaleCutoff(): Carbon
    {
        return now()->subSeconds($this->generationRunStaleSeconds());
    }

    private function runChildAuthorityExpired(AiGeneration $child): bool
    {
        $cutoff = $this->generationRunStaleCutoff();

        return match ($child->generation_status) {
            GenerationStatus::PROCESSING => $child->updated_at !== null && $child->updated_at->lte($cutoff),
            GenerationStatus::QUEUED => $child->queued_at !== null && $child->queued_at->lte($cutoff),
            default => false,
        };
    }
}
