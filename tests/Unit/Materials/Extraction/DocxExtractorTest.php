<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Extraction;

use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Services\Materials\Extraction\DocxExtractor;
use Tests\Support\Materials\MaterialExtractionFixtures;
use Tests\TestCase;

class DocxExtractorTest extends TestCase
{
    public function test_it_extracts_text_from_a_valid_document(): void
    {
        $text = (new DocxExtractor)->extract(MaterialExtractionFixtures::simpleParagraphDocx('Hello DOCX'));

        $this->assertSame("Hello DOCX\n", $text);
    }

    public function test_it_preserves_paragraph_tab_break_and_xml_space(): void
    {
        $text = (new DocxExtractor)->extract(MaterialExtractionFixtures::semanticDocx());

        $this->assertSame("Hello\nOne\tTwo\nLine\nBreak\n  spaced  \n", $text);
    }

    public function test_it_rejects_missing_document_xml(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        (new DocxExtractor)->extract(MaterialExtractionFixtures::docxMissingDocumentXml());
    }

    public function test_it_rejects_malformed_zip(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        (new DocxExtractor)->extract(MaterialExtractionFixtures::malformedZip());
    }

    public function test_it_rejects_malformed_xml(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        (new DocxExtractor)->extract(MaterialExtractionFixtures::malformedXmlDocx());
    }

    public function test_it_rejects_excessive_compression_ratio(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        (new DocxExtractor)->extract(MaterialExtractionFixtures::highCompressionRatioDocx());
    }

    public function test_it_rejects_claimed_total_uncompressed_size_over_limit(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        (new DocxExtractor)->extract(
            MaterialExtractionFixtures::claimedUncompressedDocx(DocxExtractor::MAX_ZIP_UNCOMPRESSED_BYTES + 1),
        );
    }

    public function test_it_rejects_claimed_document_xml_size_over_limit(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        (new DocxExtractor)->extract(
            MaterialExtractionFixtures::claimedDocumentXmlDocx(DocxExtractor::MAX_DOCUMENT_XML_BYTES + 1),
        );
    }

    public function test_it_rejects_encrypted_archives(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        (new DocxExtractor)->extract(MaterialExtractionFixtures::encryptedDocx());
    }

    public function test_it_cleans_up_temp_files_after_success(): void
    {
        $directory = $this->makeTempDirectory();

        try {
            (new DocxExtractor($directory))->extract(MaterialExtractionFixtures::simpleParagraphDocx());

            $this->assertSame([], $this->entries($directory));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_it_cleans_up_temp_files_after_security_failure(): void
    {
        $directory = $this->makeTempDirectory();

        try {
            try {
                (new DocxExtractor($directory))->extract(MaterialExtractionFixtures::malformedZip());
                $this->fail('Malformed ZIP must be unrecoverable.');
            } catch (UnrecoverableMaterialExtractionException) {
                // expected
            }

            $this->assertSame([], $this->entries($directory));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_it_cleans_up_temp_files_after_xml_parse_failure(): void
    {
        $directory = $this->makeTempDirectory();

        try {
            try {
                (new DocxExtractor($directory))->extract(MaterialExtractionFixtures::malformedXmlDocx());
                $this->fail('Malformed XML must be unrecoverable.');
            } catch (UnrecoverableMaterialExtractionException) {
                // expected
            }

            $this->assertSame([], $this->entries($directory));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));

        return $entries;
    }

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mtx-test-'.bin2hex(random_bytes(8));

        if (! mkdir($directory) && ! is_dir($directory)) {
            $this->fail('Unable to create extractor temp directory.');
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach ($this->entries($directory) as $entry) {
            @unlink($directory.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($directory);
    }
}
