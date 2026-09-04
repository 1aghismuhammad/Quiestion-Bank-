<?php

declare(strict_types=1);

namespace App\Support\Materials;

final class MaterialContentHasher
{
    public function hash(string $content): string
    {
        return hash('sha256', $content);
    }
}
