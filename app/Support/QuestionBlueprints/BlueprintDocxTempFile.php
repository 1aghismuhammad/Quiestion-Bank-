<?php

declare(strict_types=1);

namespace App\Support\QuestionBlueprints;

use RuntimeException;

class BlueprintDocxTempFile
{
    public function createRaw(): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'kisi');

        if ($temp === false || ! is_file($temp)) {
            throw new RuntimeException('Unable to create a temporary DOCX file.');
        }

        return $temp;
    }

    public function moveToDocx(string $rawPath): string
    {
        $path = $rawPath.'.docx';

        if (! @rename($rawPath, $path) || ! is_file($path)) {
            throw new RuntimeException('Unable to prepare a temporary DOCX file.');
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
