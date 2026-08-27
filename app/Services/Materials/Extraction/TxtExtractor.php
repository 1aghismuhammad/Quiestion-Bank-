<?php

declare(strict_types=1);

namespace App\Services\Materials\Extraction;

use App\Contracts\Materials\MaterialContentExtractor;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;

class TxtExtractor implements MaterialContentExtractor
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    private const UTF16LE_BOM = "\xFF\xFE";

    private const UTF16BE_BOM = "\xFE\xFF";

    public function extract(string $contents): string
    {
        if (str_starts_with($contents, self::UTF8_BOM)) {
            return $this->assertSafeUtf8(substr($contents, 3));
        }

        if (str_starts_with($contents, self::UTF16LE_BOM)) {
            return $this->assertSafeUtf8($this->convertUtf16(substr($contents, 2), 'UTF-16LE'));
        }

        if (str_starts_with($contents, self::UTF16BE_BOM)) {
            return $this->assertSafeUtf8($this->convertUtf16(substr($contents, 2), 'UTF-16BE'));
        }

        return $this->assertSafeUtf8($contents);
    }

    private function convertUtf16(string $payload, string $fromEncoding): string
    {
        $converted = iconv($fromEncoding, 'UTF-8', $payload);

        if ($converted === false || ! mb_check_encoding($converted, 'UTF-8')) {
            throw new UnrecoverableMaterialExtractionException('TXT content could not be converted to UTF-8.');
        }

        return $converted;
    }

    private function assertSafeUtf8(string $contents): string
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new UnrecoverableMaterialExtractionException('TXT content is not valid UTF-8.');
        }

        if (str_contains($contents, "\0")) {
            throw new UnrecoverableMaterialExtractionException('TXT content contains a NUL byte.');
        }

        return $contents;
    }
}
