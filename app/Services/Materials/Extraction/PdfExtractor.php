<?php

declare(strict_types=1);

namespace App\Services\Materials\Extraction;

use App\Contracts\Materials\MaterialContentExtractor;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use Exception;
use Smalot\PdfParser\Parser;

class PdfExtractor implements MaterialContentExtractor
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? new Parser;
    }

    public function extract(string $contents): string
    {
        try {
            return $this->parser->parseContent($contents)->getText();
        } catch (UnrecoverableMaterialExtractionException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new UnrecoverableMaterialExtractionException(
                'PDF content could not be extracted.',
                0,
                $exception,
            );
        }
    }
}
