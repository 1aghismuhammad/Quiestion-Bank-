<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Extraction;

use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Services\Materials\Extraction\TxtExtractor;
use Tests\Support\Materials\MaterialExtractionFixtures;
use Tests\TestCase;

class TxtExtractorTest extends TestCase
{
    private TxtExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new TxtExtractor;
    }

    public function test_it_extracts_valid_utf8(): void
    {
        $this->assertSame("Hello TXT\n", $this->extractor->extract(MaterialExtractionFixtures::utf8Txt()));
    }

    public function test_it_strips_utf8_bom_and_returns_utf8(): void
    {
        $this->assertSame('Hello', $this->extractor->extract(MaterialExtractionFixtures::utf8BomTxt()));
    }

    public function test_it_converts_utf16le_bom_to_utf8(): void
    {
        $this->assertSame('Hello', $this->extractor->extract(MaterialExtractionFixtures::utf16LeTxt()));
    }

    public function test_it_converts_utf16be_bom_to_utf8(): void
    {
        $this->assertSame('Hello', $this->extractor->extract(MaterialExtractionFixtures::utf16BeTxt()));
    }

    public function test_it_rejects_invalid_utf8(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extractor->extract(MaterialExtractionFixtures::invalidUtf8Txt());
    }

    public function test_it_rejects_nul_bytes_in_utf8_input(): void
    {
        $this->expectException(UnrecoverableMaterialExtractionException::class);

        $this->extractor->extract(MaterialExtractionFixtures::nulByteTxt());
    }
}
