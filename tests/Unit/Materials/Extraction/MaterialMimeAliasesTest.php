<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Extraction;

use App\Services\Materials\Extraction\MaterialMimeAliases;
use Tests\TestCase;

class MaterialMimeAliasesTest extends TestCase
{
    private MaterialMimeAliases $aliases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aliases = new MaterialMimeAliases;
    }

    public function test_it_normalizes_mime_type_parameters_and_case(): void
    {
        $this->assertSame('text/plain', $this->aliases->normalize('Text/Plain; Charset=UTF-8'));
        $this->assertSame('text/plain', $this->aliases->normalize('  text/plain; charset=utf-8  '));
    }

    public function test_it_allows_canonical_pdf_aliases(): void
    {
        $this->assertTrue($this->aliases->isAllowed('pdf', 'application/pdf'));
        $this->assertTrue($this->aliases->isAllowed('PDF', 'application/x-pdf'));
    }

    public function test_it_allows_canonical_docx_aliases(): void
    {
        $this->assertTrue($this->aliases->isAllowed('docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertTrue($this->aliases->isAllowed('docx', 'application/zip'));
        $this->assertTrue($this->aliases->isAllowed('docx', 'application/x-zip'));
        $this->assertTrue($this->aliases->isAllowed('docx', 'application/x-zip-compressed'));
    }

    public function test_it_allows_canonical_txt_aliases(): void
    {
        $this->assertTrue($this->aliases->isAllowed('txt', 'text/plain'));
        $this->assertTrue($this->aliases->isAllowed('txt', 'text/plain; charset=utf-8'));
        $this->assertTrue($this->aliases->isAllowed('txt', 'Text/Plain; Charset=UTF-8'));
        $this->assertTrue($this->aliases->isAllowed('txt', 'application/octet-stream'));
    }

    public function test_it_rejects_mismatched_extension_and_mime_pairs(): void
    {
        $this->assertFalse($this->aliases->isAllowed('pdf', 'application/zip'));
        $this->assertFalse($this->aliases->isAllowed('docx', 'application/pdf'));
        $this->assertFalse($this->aliases->isAllowed('txt', 'application/pdf'));
    }

    public function test_it_rejects_unsupported_extensions(): void
    {
        $this->assertFalse($this->aliases->isAllowed('doc', 'application/msword'));
        $this->assertFalse($this->aliases->isAllowed('rtf', 'text/plain'));
    }
}
