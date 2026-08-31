<?php

declare(strict_types=1);

namespace App\Enums;

enum OutputLanguage: string
{
    case ID = 'id';
    case EN = 'en';

    public function promptLabel(): string
    {
        return match ($this) {
            self::ID => 'Indonesian (Bahasa Indonesia)',
            self::EN => 'English',
        };
    }
}
