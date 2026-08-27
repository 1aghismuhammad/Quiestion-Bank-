<?php

declare(strict_types=1);

namespace App\Services\Materials\Extraction;

use App\Contracts\Materials\MaterialContentExtractor;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;

class MaterialExtractorRouter
{
    public const MAX_OUTPUT_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = ['pdf', 'docx', 'txt'];

    public function __construct(
        private MaterialContentExtractor $txtExtractor,
        private MaterialContentExtractor $pdfExtractor,
        private MaterialContentExtractor $docxExtractor,
        private MaterialMimeAliases $mimeAliases = new MaterialMimeAliases,
    ) {}

    public function extract(string $contents, string $extension, string $mime): string
    {
        $extension = strtolower(trim($extension));

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            throw new UnrecoverableMaterialExtractionException('Unsupported material file extension.');
        }

        if (! $this->mimeAliases->isAllowed($extension, $mime)) {
            throw new UnrecoverableMaterialExtractionException('Material file MIME type does not match the extension.');
        }

        $extracted = $this->extractorFor($extension)->extract($contents);

        if (! mb_check_encoding($extracted, 'UTF-8')) {
            throw new UnrecoverableMaterialExtractionException('Extracted material content is not valid UTF-8.');
        }

        if (str_starts_with($extracted, "\xEF\xBB\xBF")) {
            $extracted = substr($extracted, 3);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $extracted);

        if (strlen($normalized) === 0) {
            throw new UnrecoverableMaterialExtractionException('Extracted material content is empty.');
        }

        if (strlen($normalized) > self::MAX_OUTPUT_BYTES) {
            throw new UnrecoverableMaterialExtractionException('Extracted material content exceeds the 10 MiB limit.');
        }

        return $normalized;
    }

    private function extractorFor(string $extension): MaterialContentExtractor
    {
        return match ($extension) {
            'pdf' => $this->pdfExtractor,
            'docx' => $this->docxExtractor,
            'txt' => $this->txtExtractor,
        };
    }
}
