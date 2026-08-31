<?php

declare(strict_types=1);

namespace App\Support\Generations;

final class ProviderAttemptBudget
{
    public const MAX = 3;

    public static function max(): int
    {
        $configured = config('generation.max_provider_attempts', self::MAX);
        $value = is_numeric($configured) ? (int) $configured : self::MAX;

        if ($value < 1) {
            return self::MAX;
        }

        return min(self::MAX, $value);
    }
}
