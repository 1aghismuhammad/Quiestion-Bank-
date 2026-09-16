<?php

declare(strict_types=1);

namespace App\Support\QuestionSets;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;

final class PhpWordQuestionSetDocxSaver implements QuestionSetDocxSaver
{
    public function save(PhpWord $phpWord, string $path): void
    {
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        if (! is_file($path) || filesize($path) < 1) {
            throw new RuntimeException('Unable to write the DOCX file.');
        }
    }
}
