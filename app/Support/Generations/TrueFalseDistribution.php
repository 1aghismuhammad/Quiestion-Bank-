<?php

declare(strict_types=1);

namespace App\Support\Generations;

final class TrueFalseDistribution
{
    /**
     * @return array{true: int, false: int}
     */
    public static function remaining(int $requestedCount, int $acceptedTrue, int $acceptedFalse): array
    {
        $left = max(0, $requestedCount - $acceptedTrue - $acceptedFalse);
        $idealTrue = (int) ceil($requestedCount / 2);
        $needTrue = max(0, min($left, $idealTrue - $acceptedTrue));
        $needFalse = $left - $needTrue;

        return [
            'true' => $needTrue,
            'false' => $needFalse,
        ];
    }

    public static function canAccept(
        int $requestedCount,
        int $acceptedTrue,
        int $acceptedFalse,
        bool $nextIsTrue,
    ): bool {
        $true = $acceptedTrue + ($nextIsTrue ? 1 : 0);
        $false = $acceptedFalse + ($nextIsTrue ? 0 : 1);
        $have = $true + $false;
        $remaining = $requestedCount - $have;

        if ($remaining < 0) {
            return false;
        }

        if ($requestedCount <= 1) {
            return true;
        }

        $minTrue = (int) floor($requestedCount / 2);
        $maxTrue = (int) ceil($requestedCount / 2);

        return ($true + $remaining) >= $minTrue && $true <= $maxTrue;
    }

    public static function isBalanced(int $requestedCount, int $trueCount, int $falseCount): bool
    {
        if ($trueCount + $falseCount !== $requestedCount) {
            return false;
        }

        if ($requestedCount <= 1) {
            return true;
        }

        return abs($trueCount - $falseCount) <= 1;
    }

    public static function isFeasible(int $requestedCount, int $trueCount, int $falseCount): bool
    {
        if ($requestedCount < 1 || $trueCount < 0 || $falseCount < 0) {
            return false;
        }

        $have = $trueCount + $falseCount;

        if ($have > $requestedCount) {
            return false;
        }

        if ($have === $requestedCount) {
            return self::isBalanced($requestedCount, $trueCount, $falseCount);
        }

        if ($requestedCount <= 1) {
            return true;
        }

        $remaining = $requestedCount - $have;
        $minTrue = (int) floor($requestedCount / 2);
        $maxTrue = (int) ceil($requestedCount / 2);

        return $trueCount <= $maxTrue
            && $falseCount <= $maxTrue
            && ($trueCount + $remaining) >= $minTrue
            && ($falseCount + $remaining) >= $minTrue;
    }
}
