<?php

declare(strict_types=1);

namespace App\Support\QuestionSets;

final class QuestionSetDocxFilename
{
    public static function for(string $title, bool $teacher): string
    {
        $safe = preg_replace('/[^\p{L}\p{N}\- ]+/u', '', $title) ?? '';
        $safe = trim((string) preg_replace('/\s+/u', '-', $safe), '-');

        if ($safe === '') {
            $safe = 'soal';
        }

        $safe = mb_substr($safe, 0, 80, 'UTF-8');

        return $teacher ? 'Soal-Kunci-'.$safe.'.docx' : 'Soal-'.$safe.'.docx';
    }
}
