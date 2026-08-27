<?php

declare(strict_types=1);

namespace App\Services\Materials\Extraction;

class MaterialMimeAliases
{
    /**
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'pdf' => [
            'application/pdf',
            'application/x-pdf',
        ],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
        ],
        'txt' => [
            'text/plain',
            'application/octet-stream',
        ],
    ];

    public function normalize(string $mime): string
    {
        $mime = trim($mime);
        $separator = strpos($mime, ';');

        if ($separator !== false) {
            $mime = substr($mime, 0, $separator);
        }

        return strtolower(trim($mime));
    }

    public function isAllowed(string $extension, string $mime): bool
    {
        $extension = strtolower(trim($extension));
        $aliases = self::ALIASES[$extension] ?? [];

        return in_array($this->normalize($mime), $aliases, true);
    }
}
