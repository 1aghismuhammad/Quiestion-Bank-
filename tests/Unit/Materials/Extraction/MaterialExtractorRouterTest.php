<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Extraction;

use App\Contracts\Materials\MaterialContentExtractor;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Services\Materials\Extraction\DocxExtractor;
use App\Services\Materials\Extraction\MaterialExtractorRouter;
use App\Services\Materials\Extraction\PdfExtractor;
use App\Services\Materials\Extraction\TxtExtractor;
use Tests\Support\Materials\MaterialExtractionFixtures;
use Tests\TestCase;

class MaterialExtractorRouterTest extends TestCase
{
    public function test_it_routes_txt_only_to_the_txt_extractor(): void
    {
        [$txt, $pdf, $docx] = $this->recorders('from-txt', 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        $this->assertSame('from-txt', $router->extract('payload', 'txt', 'text/plain'));
        $this->assertSame(1, $txt->calls);
        $this->assertSame(0, $pdf->calls);
        $this->assertSame(0, $docx->calls);
    }

    public function test_it_routes_pdf_only_to_the_pdf_extractor(): void
    {
        [$txt, $pdf, $docx] = $this->recorders('from-txt', 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        $this->assertSame('from-pdf', $router->extract('payload', 'pdf', 'application/pdf'));
        $this->assertSame(0, $txt->calls);
        $this->assertSame(1, $pdf->calls);
        $this->assertSame(0, $docx->calls);
    }

    public function test_it_routes_docx_only_to_the_docx_extractor(): void
    {
        [$txt, $pdf, $docx] = $this->recorders('from-txt', 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        $this->assertSame(
            'from-docx',
            $router->extract('payload', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        );
        $this->assertSame(0, $txt->calls);
        $this->assertSame(0, $pdf->calls);
        $this->assertSame(1, $docx->calls);
    }

    public function test_it_rejects_mime_mismatch_before_calling_extractors(): void
    {
        [$txt, $pdf, $docx] = $this->recorders('from-txt', 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        try {
            $router->extract('payload', 'pdf', 'application/zip');
            $this->fail('MIME mismatch must be unrecoverable.');
        } catch (UnrecoverableMaterialExtractionException) {
            $this->assertSame(0, $txt->calls);
            $this->assertSame(0, $pdf->calls);
            $this->assertSame(0, $docx->calls);
        }
    }

    public function test_it_rejects_unsupported_extension_before_calling_extractors(): void
    {
        [$txt, $pdf, $docx] = $this->recorders('from-txt', 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        try {
            $router->extract('payload', 'rtf', 'text/plain');
            $this->fail('Unsupported extension must be unrecoverable.');
        } catch (UnrecoverableMaterialExtractionException) {
            $this->assertSame(0, $txt->calls);
            $this->assertSame(0, $pdf->calls);
            $this->assertSame(0, $docx->calls);
        }
    }

    public function test_it_rejects_invalid_utf8_from_any_extractor(): void
    {
        [$txt, $pdf, $docx] = $this->recorders("\xC3\x28", 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $router->extract('payload', 'txt', 'text/plain');
    }

    public function test_it_rejects_empty_extractor_output(): void
    {
        [$txt, $pdf, $docx] = $this->recorders('', 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $router->extract('payload', 'txt', 'text/plain');
    }

    public function test_it_accepts_output_of_exactly_10_mib(): void
    {
        $content = str_repeat('a', MaterialExtractorRouter::MAX_OUTPUT_BYTES);
        [$txt, $pdf, $docx] = $this->recorders($content, 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        $extracted = $router->extract('payload', 'txt', 'text/plain');

        $this->assertSame($content, $extracted);
        $this->assertSame(MaterialExtractorRouter::MAX_OUTPUT_BYTES, strlen($extracted));
    }

    public function test_it_rejects_output_of_10_mib_plus_one_byte_without_truncation(): void
    {
        $content = str_repeat('a', MaterialExtractorRouter::MAX_OUTPUT_BYTES + 1);
        [$txt, $pdf, $docx] = $this->recorders($content, 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        try {
            $router->extract('payload', 'txt', 'text/plain');
            $this->fail('Over-limit output must be unrecoverable.');
        } catch (UnrecoverableMaterialExtractionException $exception) {
            $this->assertStringContainsString('10 MiB', $exception->getMessage());
            $this->assertSame(MaterialExtractorRouter::MAX_OUTPUT_BYTES + 1, strlen($content));
        }
    }

    public function test_it_normalizes_crlf_and_cr_to_lf(): void
    {
        $router = new MaterialExtractorRouter(new TxtExtractor, new PdfExtractor, new DocxExtractor);

        $this->assertSame(
            "a\nb\nc",
            $router->extract("a\r\nb\rc", 'txt', 'text/plain'),
        );
    }

    public function test_it_strips_utf8_bom_after_extraction(): void
    {
        $router = new MaterialExtractorRouter(new TxtExtractor, new PdfExtractor, new DocxExtractor);

        $this->assertSame(
            'Hello',
            $router->extract(MaterialExtractionFixtures::utf8BomTxt(), 'txt', 'text/plain'),
        );
    }

    public function test_whitespace_only_output_is_not_empty(): void
    {
        [$txt, $pdf, $docx] = $this->recorders(" \t\n", 'from-pdf', 'from-docx');
        $router = new MaterialExtractorRouter($txt, $pdf, $docx);

        $this->assertSame(" \t\n", $router->extract('payload', 'txt', 'text/plain'));
    }

    /**
     * @return array{0: RecordingMaterialExtractor, 1: RecordingMaterialExtractor, 2: RecordingMaterialExtractor}
     */
    private function recorders(string $txt, string $pdf, string $docx): array
    {
        return [
            new RecordingMaterialExtractor($txt),
            new RecordingMaterialExtractor($pdf),
            new RecordingMaterialExtractor($docx),
        ];
    }
}

final class RecordingMaterialExtractor implements MaterialContentExtractor
{
    public int $calls = 0;

    public function __construct(private string $result) {}

    public function extract(string $contents): string
    {
        $this->calls++;

        return $this->result;
    }
}
