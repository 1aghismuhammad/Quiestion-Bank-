<?php

declare(strict_types=1);

namespace App\Support\QuestionBlueprints;

use PhpOffice\PhpWord\PhpWord;

interface BlueprintDocxSaver
{
    public function save(PhpWord $phpWord, string $path): void;
}
