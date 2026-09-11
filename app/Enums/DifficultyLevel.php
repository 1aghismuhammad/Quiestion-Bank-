<?php

declare(strict_types=1);

namespace App\Enums;

enum DifficultyLevel: string
{
    case EASY = 'easy';
    case MEDIUM = 'medium';
    case HARD = 'hard';
    case HOTS = 'hots';

    public function label(): string
    {
        return match ($this) {
            self::EASY => 'Mudah',
            self::MEDIUM => 'Sedang',
            self::HARD => 'Sulit',
            self::HOTS => 'HOTS',
        };
    }
}
