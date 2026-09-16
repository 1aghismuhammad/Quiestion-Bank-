<?php

declare(strict_types=1);

namespace App\Support\QuestionSets;

use PhpOffice\PhpWord\PhpWord;

interface QuestionSetDocxSaver
{
    public function save(PhpWord $phpWord, string $path): void;
}
