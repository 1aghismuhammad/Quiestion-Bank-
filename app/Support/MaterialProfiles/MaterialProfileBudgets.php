<?php

declare(strict_types=1);

namespace App\Support\MaterialProfiles;

/**
 * Bounded map/reduce coverage. Every persisted extracted Element must fit in
 * the reduce summary budget, so reduce never truncates.
 */
final class MaterialProfileBudgets
{
    public const UNSIGNED_INT_MAX = 4_294_967_295;

    public const PROVIDER_MAX_LENGTH = 32;

    public const MODEL_MAX_LENGTH = 100;

    public const PROMPT_VERSION_MAX_LENGTH = 32;

    public static function maxMapCandidates(): int
    {
        return max(1, (int) config('material_profile.max_map_candidates', 10));
    }

    public static function maxChunks(): int
    {
        return max(1, (int) config('material_profile.max_chunks', 20));
    }

    public static function maxReduceSummaries(): int
    {
        return max(1, (int) config('material_profile.max_reduce_summaries', 200));
    }

    /**
     * Hard ceiling on persisted extracted Elements for one Profile Version.
     */
    public static function extractedElementBudget(): int
    {
        return min(self::maxMapCandidates() * self::maxChunks(), self::maxReduceSummaries());
    }

    public static function configurationAllowsLosslessReduce(): bool
    {
        return self::maxMapCandidates() * self::maxChunks() <= self::maxReduceSummaries();
    }
}
