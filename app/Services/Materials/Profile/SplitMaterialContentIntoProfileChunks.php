<?php

declare(strict_types=1);

namespace App\Services\Materials\Profile;

use App\Data\MaterialProfiles\ProfileChunkSplit;
use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Support\Materials\MaterialContentHasher;

final class SplitMaterialContentIntoProfileChunks
{
    public function __construct(private MaterialContentHasher $hasher) {}

    /**
     * @return list<ProfileChunkSplit>
     */
    public function handle(string $content): array
    {
        $length = mb_strlen($content, 'UTF-8');
        $maxChars = (int) config('material_profile.max_canonical_chars');
        $coreMax = (int) config('material_profile.chunk_core_max_chars');
        $maxChunks = (int) config('material_profile.max_chunks');
        $overlap = (int) config('material_profile.chunk_overlap_chars');

        if ($length === 0) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialEmpty);
        }

        if ($length > $maxChars) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialTooLarge);
        }

        $cores = $this->preferredCores($content, $length, $coreMax);

        if (count($cores) > $maxChunks) {
            $cores = $this->hardCores($length, $coreMax);
        }

        if (count($cores) > $maxChunks) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialTooLarge);
        }

        return $this->withOverlapAndHashes($content, $cores, $overlap);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function preferredCores(string $content, int $length, int $coreMax): array
    {
        $cores = [];
        $start = 0;

        while ($start < $length) {
            $remaining = $length - $start;

            if ($remaining <= $coreMax) {
                $cores[] = [$start, $length];
                break;
            }

            $window = mb_substr($content, $start, $coreMax, 'UTF-8');
            $cut = $this->lastPreferredBoundary($window);

            if ($cut === null || $cut < 1) {
                $cut = $coreMax;
            }

            $cores[] = [$start, $start + $cut];
            $start += $cut;
        }

        return $cores;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function hardCores(int $length, int $coreMax): array
    {
        $cores = [];
        $start = 0;

        while ($start < $length) {
            $end = min($start + $coreMax, $length);
            $cores[] = [$start, $end];
            $start = $end;
        }

        return $cores;
    }

    private function lastPreferredBoundary(string $window): ?int
    {
        $blank = mb_strrpos($window, "\n\n", 0, 'UTF-8');

        if (is_int($blank) && $blank > 0) {
            return $blank + 2;
        }

        $newline = mb_strrpos($window, "\n", 0, 'UTF-8');

        if (is_int($newline) && $newline > 0) {
            return $newline + 1;
        }

        foreach (['. ', '? ', '! ', '。'] as $punctuation) {
            $pos = mb_strrpos($window, $punctuation, 0, 'UTF-8');

            if (is_int($pos) && $pos > 0) {
                return $pos + mb_strlen($punctuation, 'UTF-8');
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $cores
     * @return list<ProfileChunkSplit>
     */
    private function withOverlapAndHashes(string $content, array $cores, int $overlap): array
    {
        $chunks = [];

        foreach ($cores as $index => [$start, $end]) {
            $overlapStart = null;
            $overlapEnd = null;

            if ($index > 0) {
                $previousStart = $cores[$index - 1][0];
                $previousEnd = $cores[$index - 1][1];
                $previousCoreLength = $previousEnd - $previousStart;
                $overlapLength = min($overlap, $previousCoreLength);

                if ($overlapLength > 0) {
                    $overlapStart = $start - $overlapLength;
                    $overlapEnd = $start;
                }
            }

            $core = mb_substr($content, $start, $end - $start, 'UTF-8');

            $chunks[] = new ProfileChunkSplit(
                chunkIndex: $index,
                charStart: $start,
                charEnd: $end,
                overlapBeforeStart: $overlapStart,
                overlapBeforeEnd: $overlapEnd,
                coreTextHash: $this->hasher->hash($core),
            );
        }

        return $chunks;
    }
}
