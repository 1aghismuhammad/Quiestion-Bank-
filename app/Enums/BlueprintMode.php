<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintMode: string
{
    case Simple = 'simple';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Sederhana',
            self::Advanced => 'Lanjutan',
        };
    }

    public function toRunMode(): GenerationRunMode
    {
        return match ($this) {
            self::Simple => GenerationRunMode::Simple,
            self::Advanced => GenerationRunMode::Advanced,
        };
    }
}
