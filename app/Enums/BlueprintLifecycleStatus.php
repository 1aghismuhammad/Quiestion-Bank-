<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintLifecycleStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';

    public function isConfirmed(): bool
    {
        return $this === self::Confirmed;
    }
}
