<?php

declare(strict_types=1);

namespace App\Support\QuestionBlueprints;

final class BlueprintDocxFilename
{
    public static function for(string $title): string
    {
        $safe = preg_replace('/[^\p{L}\p{N}\- ]+/u', '', $title) ?? '';
        $safe = trim((string) preg_replace('/\s+/u', '-', $safe), '-');

        if ($safe === '') {
            $safe = 'kisi';
        }

        $safe = mb_substr($safe, 0, 80, 'UTF-8');

        return 'Kisi-Kisi-'.$safe.'.docx';
    }
}
