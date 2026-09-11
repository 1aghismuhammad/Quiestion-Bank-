<?php

declare(strict_types=1);

namespace App\Support\Generations;

final class RunItemSpanWindows
{
    public const SEPARATOR = "\n\n";

    /**
     * Merge overlapping windows from the same Chunk for provider-facing
     * material only. Adjacent touching intervals stay separate.
     *
     * @param  list<array{char_start: int, char_end: int, profile_chunk_id: int, rank?: int}>  $windows
     * @return list<array{char_start: int, char_end: int, profile_chunk_id: int, rank: int}>
     */
    public static function mergeOverlappingSameChunk(array $windows): array
    {
        $grouped = [];

        foreach ($windows as $window) {
            $grouped[(int) $window['profile_chunk_id']][] = $window;
        }

        ksort($grouped);

        $merged = [];

        foreach ($grouped as $chunkId => $group) {
            usort(
                $group,
                function (array $left, array $right): int {
                    $start = $left['char_start'] <=> $right['char_start'];

                    return $start !== 0 ? $start : (($left['rank'] ?? 0) <=> ($right['rank'] ?? 0));
                },
            );

            $chunkMerged = [];

            foreach ($group as $window) {
                $last = $chunkMerged === [] ? null : $chunkMerged[array_key_last($chunkMerged)];

                if ($last !== null && $last['char_end'] > $window['char_start']) {
                    $chunkMerged[array_key_last($chunkMerged)]['char_end'] = max($last['char_end'], $window['char_end']);

                    continue;
                }

                $chunkMerged[] = [
                    'char_start' => (int) $window['char_start'],
                    'char_end' => (int) $window['char_end'],
                    'profile_chunk_id' => (int) $chunkId,
                    'rank' => (int) ($window['rank'] ?? 1),
                ];
            }

            foreach ($chunkMerged as $item) {
                $merged[] = $item;
            }
        }

        usort(
            $merged,
            function (array $left, array $right): int {
                $start = $left['char_start'] <=> $right['char_start'];

                if ($start !== 0) {
                    return $start;
                }

                $chunk = $left['profile_chunk_id'] <=> $right['profile_chunk_id'];

                return $chunk !== 0 ? $chunk : ($left['rank'] <=> $right['rank']);
            },
        );

        return $merged;
    }

    /**
     * @param  list<array{char_start: int, char_end: int, profile_chunk_id: int, rank?: int}>  $windows
     * @return list<string>
     */
    public static function slices(string $content, array $windows): array
    {
        $pieces = [];

        foreach (self::mergeOverlappingSameChunk($windows) as $window) {
            $pieces[] = mb_substr($content, $window['char_start'], $window['char_end'] - $window['char_start'], 'UTF-8');
        }

        return $pieces;
    }

    /**
     * @param  list<array{char_start: int, char_end: int, profile_chunk_id: int, rank?: int}>  $windows
     */
    public static function join(string $content, array $windows): string
    {
        return implode(self::SEPARATOR, self::slices($content, $windows));
    }
}
