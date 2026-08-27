<?php

declare(strict_types=1);

namespace App\Contracts\Materials;

interface MaterialContentExtractor
{
    public function extract(string $contents): string;
}
