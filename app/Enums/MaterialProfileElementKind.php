<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileElementKind: string
{
    case TOPIC = 'topic';
    case OBJECTIVE = 'objective';
    case INDICATOR = 'indicator';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TOPIC => 'Topik',
            self::OBJECTIVE => 'Tujuan',
            self::INDICATOR => 'Indikator',
            self::OTHER => 'Catatan',
        };
    }
}
