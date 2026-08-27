<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Extraction;

use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Services\Materials\Extraction\PdfExtractor;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;
use Tests\Support\Materials\MaterialExtractionFixtures;
use Tests\TestCase;

class PdfExtractorTest extends TestCase
{
    public function test_it_extracts_text_from_a_small_valid_pdf(): void
    {
        $text = (new PdfExtractor)->extract(MaterialExtractionFixtures::extractablePdf());

        $this->assertNotSame('', trim($text));
        $this->assertStringContainsString('Hello PDF', $text);
    }

    public function test_it_rejects_corrupt_pdf(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        (new PdfExtractor)->extract(MaterialExtractionFixtures::corruptPdf());
    }

    public function test_it_returns_empty_text_for_a_no_text_pdf(): void
    {
        $text = (new PdfExtractor)->extract(MaterialExtractionFixtures::emptyTextPdf());

        $this->assertSame('', $text);
    }

    public function test_it_maps_parser_encryption_failures_to_unrecoverable(): void
    {
        $parser = new class extends Parser
        {
            public function parseContent(string $content): Document
            {
                throw new \Exception('Secured pdf file are currently not supported.');
            }
        };

        try {
            (new PdfExtractor($parser))->extract('%PDF-1.4 encrypted');
            $this->fail('Encrypted PDF parser failure must be unrecoverable.');
        } catch (UnrecoverableMaterialExtractionException $exception) {
            $this->assertInstanceOf(\Exception::class, $exception->getPrevious());
            $this->assertSame('Secured pdf file are currently not supported.', $exception->getPrevious()->getMessage());
        }
    }

    public function test_it_does_not_map_unexpected_parser_errors_to_unrecoverable(): void
    {
        $parser = new class extends Parser
        {
            public function parseContent(string $content): Document
            {
                throw new \Error('unexpected parser error');
            }
        };

        try {
            (new PdfExtractor($parser))->extract('%PDF-1.4');
            $this->fail('Unexpected Error must bubble.');
        } catch (UnrecoverableMaterialExtractionException) {
            $this->fail('Unexpected Error must not become unrecoverable.');
        } catch (\Error $error) {
            $this->assertSame('unexpected parser error', $error->getMessage());
        }
    }
}
