<?php

declare(strict_types=1);

namespace App\Support\Generations;

final class GenerationCredits
{
    public static function required(int $questionCount): int
    {
        if ($questionCount < 1) {
            return 0;
        }

        return (int) ceil($questionCount / 10);
    }
}
