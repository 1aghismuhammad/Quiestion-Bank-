<?php

declare(strict_types=1);

namespace App\Services\QuestionBlueprints;

use App\Data\QuestionBlueprints\ImportStructuredDocument;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Services\Materials\Extraction\DocxExtractor;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

class BlueprintImportDocxStructureExtractor
{
    private const DOCUMENT_XML_NAME = 'word/document.xml';

    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const TEMP_PREFIX = 'bis';

    public function __construct(private ?string $temporaryDirectory = null) {}

    public function extract(string $contents): ImportStructuredDocument
    {
        $tempPath = tempnam($this->temporaryDirectory(), self::TEMP_PREFIX);

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary blueprint import file.');
        }

        $handle = null;
        $zip = null;
        $zipOpened = false;

        try {
            $handle = fopen($tempPath, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open a temporary blueprint import file.');
            }

            $this->writeAll($handle, $contents);

            if (fclose($handle) === false) {
                $handle = null;

                throw new RuntimeException('Unable to close a temporary blueprint import file.');
            }

            $handle = null;

            $zip = new ZipArchive;
            $opened = $zip->open($tempPath);

            if ($opened !== true) {
                if ($opened === ZipArchive::ER_NOPASSWD) {
                    throw new UnrecoverableMaterialExtractionException('Encrypted DOCX archives are not supported.');
                }

                throw new UnrecoverableMaterialExtractionException('DOCX archive could not be opened.');
            }

            $zipOpened = true;
            $documentXmlIndex = $this->assertArchiveSafe($zip);
            $xml = $zip->getFromIndex($documentXmlIndex);

            if ($xml === false) {
                if ($zip->status === ZipArchive::ER_NOPASSWD) {
                    throw new UnrecoverableMaterialExtractionException('Encrypted DOCX archives are not supported.');
                }

                throw new UnrecoverableMaterialExtractionException('DOCX document.xml could not be read.');
            }

            if (strlen($xml) > DocxExtractor::MAX_DOCUMENT_XML_BYTES) {
                throw new UnrecoverableMaterialExtractionException('word/document.xml exceeds the size limit.');
            }

            return $this->extractStructureFromDocumentXml($xml);
        } finally {
            $this->closeHandle($handle, $tempPath);
            $this->closeOpenedZip($zip, $zipOpened, $tempPath);
            $this->deleteTemporaryFile($tempPath);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function writeAll($handle, string $contents): void
    {
        $length = strlen($contents);
        $totalWritten = 0;

        while ($totalWritten < $length) {
            $written = fwrite($handle, substr($contents, $totalWritten));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Incomplete blueprint import temporary file write.');
            }

            $totalWritten += $written;
        }

        if ($totalWritten !== $length) {
            throw new RuntimeException('Incomplete blueprint import temporary file write.');
        }
    }

    private function assertArchiveSafe(ZipArchive $zip): int
    {
        $totalUncompressed = 0;
        $totalCompressed = 0;
        $documentXmlIndex = null;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false || ! isset($stat['name'], $stat['size'], $stat['comp_size'])) {
                throw new UnrecoverableMaterialExtractionException('DOCX archive metadata is unusable.');
            }

            if (isset($stat['encryption_method']) && $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new UnrecoverableMaterialExtractionException('Encrypted DOCX archives are not supported.');
            }

            if (! is_numeric($stat['size']) || ! is_numeric($stat['comp_size'])) {
                throw new UnrecoverableMaterialExtractionException('DOCX archive metadata is unusable.');
            }

            $uncompressed = (int) $stat['size'];
            $compressed = (int) $stat['comp_size'];

            if ($uncompressed < 0 || $compressed < 0 || (float) $stat['size'] < 0 || (float) $stat['comp_size'] < 0) {
                throw new UnrecoverableMaterialExtractionException('DOCX archive metadata is unusable.');
            }

            if ($uncompressed > DocxExtractor::MAX_ZIP_UNCOMPRESSED_BYTES) {
                throw new UnrecoverableMaterialExtractionException('DOCX archive exceeds ZIP security limits.');
            }

            if ($this->isDocumentXmlName((string) $stat['name'])) {
                if ($uncompressed > DocxExtractor::MAX_DOCUMENT_XML_BYTES) {
                    throw new UnrecoverableMaterialExtractionException('word/document.xml exceeds the size limit.');
                }

                $documentXmlIndex = $index;
            }

            $totalUncompressed += $uncompressed;
            $totalCompressed += $compressed;

            if ($totalUncompressed > DocxExtractor::MAX_ZIP_UNCOMPRESSED_BYTES) {
                throw new UnrecoverableMaterialExtractionException('DOCX archive exceeds ZIP security limits.');
            }
        }

        if ($totalCompressed === 0 && $totalUncompressed > 0) {
            throw new UnrecoverableMaterialExtractionException('DOCX archive exceeds ZIP security limits.');
        }

        if ($totalCompressed === 0 && $totalUncompressed === 0) {
            throw new UnrecoverableMaterialExtractionException('DOCX archive metadata is unusable.');
        }

        if (($totalUncompressed / $totalCompressed) > DocxExtractor::MAX_ZIP_COMPRESSION_RATIO) {
            throw new UnrecoverableMaterialExtractionException('DOCX archive exceeds ZIP security limits.');
        }

        if ($documentXmlIndex === null) {
            throw new UnrecoverableMaterialExtractionException('DOCX archive is missing word/document.xml.');
        }

        return $documentXmlIndex;
    }

    private function extractStructureFromDocumentXml(string $xml): ImportStructuredDocument
    {
        $reader = new XMLReader;
        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (! $reader->xml($xml, null, LIBXML_NONET)) {
                throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.');
            }

            if (defined('XMLReader::LOADDTD')) {
                $reader->setParserProperty(XMLReader::LOADDTD, false);
            }

            if (defined('XMLReader::SUBST_ENTITIES')) {
                $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            }

            $blocks = [];
            $ordinal = 0;
            $tableIndex = 0;
            $tableDepth = 0;
            $currentTable = null;
            $currentRow = null;
            $currentCell = null;
            $paragraphContext = null;
            $paragraphBuffer = '';
            $tableCount = 0;
            $rowCount = 0;
            $cellCount = 0;
            $documentClosed = false;

            while ($reader->read()) {
                $isElement = $reader->nodeType === XMLReader::ELEMENT;
                $isEnd = $reader->nodeType === XMLReader::END_ELEMENT;
                $isEmpty = $isElement && $reader->isEmptyElement;

                if ($isElement && $this->isWordElement($reader, 'tbl')) {
                    if ($tableDepth > 0) {
                        throw new UnrecoverableMaterialExtractionException('Nested tables are not supported.');
                    }

                    $tableCount++;
                    $this->assertWithinBound('import_structure_max_tables', $tableCount, 50);
                    $tableDepth = 1;
                    $currentTable = ['rows' => []];

                    if ($isEmpty) {
                        $this->finishTable($blocks, $ordinal, $tableIndex, $currentTable, $tableDepth);
                    }

                    continue;
                }

                if ($isElement && $this->isWordElement($reader, 'tr') && $tableDepth === 1) {
                    $rowCount++;
                    $this->assertWithinBound('import_structure_max_rows', $rowCount, 500);
                    $currentRow = [
                        'row_index' => count($currentTable['rows'] ?? []),
                        'tbl_header' => false,
                        'cells' => [],
                    ];

                    if ($isEmpty) {
                        $this->finishRow($currentTable, $currentRow);
                    }

                    continue;
                }

                if ($isElement && $this->isWordElement($reader, 'tblHeader') && is_array($currentRow)) {
                    $currentRow['tbl_header'] = $this->tblHeaderValue($reader);

                    continue;
                }

                if ($isElement && $this->isWordElement($reader, 'tc') && is_array($currentRow)) {
                    $cellCount++;
                    $this->assertWithinBound('import_structure_max_cells', $cellCount, 5000);
                    $currentCell = [
                        'cell_index' => count($currentRow['cells']),
                        'paragraphs' => [],
                        'grid_span' => 1,
                        'v_merge' => null,
                    ];

                    if ($isEmpty) {
                        $this->finishCell($currentRow, $currentCell);
                    }

                    continue;
                }

                if ($isElement && $this->isWordElement($reader, 'gridSpan') && is_array($currentCell)) {
                    $currentCell['grid_span'] = $this->requirePositiveIntAttribute($reader, 'gridSpan');

                    continue;
                }

                if ($isElement && $this->isWordElement($reader, 'vMerge') && is_array($currentCell)) {
                    $currentCell['v_merge'] = $this->vMergeValue($reader);

                    continue;
                }

                if ($isElement && $this->isWordElement($reader, 'p')) {
                    if (is_array($currentCell)) {
                        $paragraphContext = 'cell';
                        $paragraphBuffer = '';
                    } elseif ($tableDepth === 0) {
                        $paragraphContext = 'body';
                        $paragraphBuffer = '';
                    }

                    if ($isEmpty && $paragraphContext !== null) {
                        $this->finishParagraph($blocks, $ordinal, $paragraphContext, $paragraphBuffer, $currentCell);
                    }

                    continue;
                }

                if ($isElement && $paragraphContext !== null && $this->isWordElement($reader, 't')) {
                    $chunk = $reader->readString();

                    if ($chunk === false) {
                        throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.');
                    }

                    $paragraphBuffer .= $chunk;

                    continue;
                }

                if ($isElement && $paragraphContext !== null && $this->isWordElement($reader, 'tab')) {
                    $paragraphBuffer .= "\t";

                    continue;
                }

                if ($isElement && $paragraphContext !== null && $this->isWordElement($reader, 'br')) {
                    $paragraphBuffer .= "\n";

                    continue;
                }

                if ($isEnd && $this->isWordElement($reader, 'p') && $paragraphContext !== null) {
                    $this->finishParagraph($blocks, $ordinal, $paragraphContext, $paragraphBuffer, $currentCell);

                    continue;
                }

                if ($isEnd && $this->isWordElement($reader, 'tc')) {
                    $this->finishCell($currentRow, $currentCell);

                    continue;
                }

                if ($isEnd && $this->isWordElement($reader, 'tr')) {
                    $this->finishRow($currentTable, $currentRow);

                    continue;
                }

                if ($isEnd && $this->isWordElement($reader, 'tbl')) {
                    $this->finishTable($blocks, $ordinal, $tableIndex, $currentTable, $tableDepth);

                    continue;
                }

                if ($isEnd && $this->isWordElement($reader, 'document')) {
                    $documentClosed = true;
                }
            }

            if (! $documentClosed || $this->xmlHadBlockingErrors()) {
                throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.');
            }

            if ($tableDepth !== 0 || $currentRow !== null || $currentCell !== null || $paragraphContext !== null) {
                throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.');
            }

            if ($blocks === []) {
                throw new UnrecoverableMaterialExtractionException('DOCX document.xml contains no supported structure.');
            }

            return new ImportStructuredDocument($blocks);
        } catch (UnrecoverableMaterialExtractionException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.', 0, $exception);
        } finally {
            $this->closeXmlReader($reader);
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function finishParagraph(
        array &$blocks,
        int &$ordinal,
        ?string &$paragraphContext,
        string &$paragraphBuffer,
        ?array &$currentCell,
    ): void {
        if ($paragraphContext === 'cell' && is_array($currentCell)) {
            $currentCell['paragraphs'][] = $paragraphBuffer;
            $this->assertWithinBound(
                'import_structure_max_paragraphs_per_cell',
                count($currentCell['paragraphs']),
                50,
            );
            $cellChars = 0;

            foreach ($currentCell['paragraphs'] as $paragraph) {
                $cellChars += strlen((string) $paragraph);
            }

            $this->assertWithinBound('import_structure_max_cell_chars', $cellChars, 8000);
        } elseif ($paragraphContext === 'body') {
            $this->assertWithinBound('import_structure_max_blocks', count($blocks) + 1, 500);
            $blocks[] = [
                'type' => 'paragraph',
                'ordinal' => $ordinal,
                'text' => $paragraphBuffer,
            ];
            $ordinal++;
        }

        $paragraphContext = null;
        $paragraphBuffer = '';
    }

    /**
     * @param  array<string, mixed>|null  $currentRow
     * @param  array<string, mixed>|null  $currentCell
     */
    private function finishCell(?array &$currentRow, ?array &$currentCell): void
    {
        if (! is_array($currentRow) || ! is_array($currentCell)) {
            throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.');
        }

        $paragraphs = [];

        foreach ($currentCell['paragraphs'] as $paragraph) {
            $paragraphs[] = (string) $paragraph;
        }

        $empty = true;

        foreach ($paragraphs as $paragraph) {
            if (trim($paragraph) !== '') {
                $empty = false;

                break;
            }
        }

        $currentRow['cells'][] = [
            'cell_index' => $currentCell['cell_index'],
            'paragraphs' => $paragraphs,
            'grid_span' => $currentCell['grid_span'],
            'v_merge' => $currentCell['v_merge'],
            'empty' => $empty,
        ];
        $currentCell = null;
    }

    /**
     * @param  array<string, mixed>|null  $currentTable
     * @param  array<string, mixed>|null  $currentRow
     */
    private function finishRow(?array &$currentTable, ?array &$currentRow): void
    {
        if (! is_array($currentTable) || ! is_array($currentRow)) {
            throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.');
        }

        $currentTable['rows'][] = [
            'row_index' => $currentRow['row_index'],
            'tbl_header' => $currentRow['tbl_header'] === true,
            'cells' => $currentRow['cells'],
        ];
        $currentRow = null;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>|null  $currentTable
     */
    private function finishTable(
        array &$blocks,
        int &$ordinal,
        int &$tableIndex,
        ?array &$currentTable,
        int &$tableDepth,
    ): void {
        if (! is_array($currentTable) || $tableDepth !== 1) {
            throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.');
        }

        $this->assertWithinBound('import_structure_max_blocks', count($blocks) + 1, 500);
        $blocks[] = [
            'type' => 'table',
            'ordinal' => $ordinal,
            'table_index' => $tableIndex,
            'rows' => $currentTable['rows'],
        ];
        $ordinal++;
        $tableIndex++;
        $tableDepth = 0;
        $currentTable = null;
    }

    private function requirePositiveIntAttribute(XMLReader $reader, string $name): int
    {
        $raw = $this->wordAttribute($reader, 'val');

        if ($raw === null || $raw === '' || ! ctype_digit($raw) || (int) $raw < 1) {
            throw new UnrecoverableMaterialExtractionException('DOCX '.$name.' value is invalid.');
        }

        return (int) $raw;
    }

    private function tblHeaderValue(XMLReader $reader): bool
    {
        $raw = $this->wordAttribute($reader, 'val');

        if ($raw === null || $raw === '') {
            return true;
        }

        $normalized = strtolower($raw);

        if (in_array($normalized, ['1', 'true', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'off'], true)) {
            return false;
        }

        throw new UnrecoverableMaterialExtractionException('DOCX tblHeader value is invalid.');
    }

    private function vMergeValue(XMLReader $reader): string
    {
        $raw = $this->wordAttribute($reader, 'val');

        if ($raw === null || $raw === '') {
            return 'continue';
        }

        $normalized = strtolower($raw);

        if ($normalized === 'restart') {
            return 'restart';
        }

        if ($normalized === 'continue') {
            return 'continue';
        }

        throw new UnrecoverableMaterialExtractionException('DOCX vMerge value is invalid.');
    }

    private function wordAttribute(XMLReader $reader, string $localName): ?string
    {
        $namespaced = $reader->getAttributeNs($localName, self::WORD_NAMESPACE);

        if (is_string($namespaced) && $namespaced !== '') {
            return $namespaced;
        }

        $prefixed = $reader->getAttribute('w:'.$localName);

        if (is_string($prefixed) && $prefixed !== '') {
            return $prefixed;
        }

        $plain = $reader->getAttribute($localName);

        return is_string($plain) ? $plain : null;
    }

    private function xmlHadBlockingErrors(): bool
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                return true;
            }
        }

        return false;
    }

    private function assertWithinBound(string $configKey, int $count, int $fallback): void
    {
        $max = (int) config('question_blueprint.'.$configKey, $fallback);

        if ($count > $max) {
            throw new UnrecoverableMaterialExtractionException('Blueprint import DOCX structure exceeds the size limit.');
        }
    }

    private function isWordElement(XMLReader $reader, string $localName): bool
    {
        if ($reader->localName !== $localName) {
            return false;
        }

        $namespace = $reader->namespaceURI;

        return $namespace === self::WORD_NAMESPACE
            || $namespace === ''
            || $reader->prefix === 'w';
    }

    private function isDocumentXmlName(string $name): bool
    {
        return str_replace('\\', '/', ltrim($name, '/')) === self::DOCUMENT_XML_NAME;
    }

    private function temporaryDirectory(): string
    {
        return $this->temporaryDirectory ?? sys_get_temp_dir();
    }

    private function closeHandle(mixed $handle, string $tempPath): void
    {
        if (! is_resource($handle)) {
            return;
        }

        try {
            if (fclose($handle) === false) {
                $this->logCleanupFailure($tempPath, RuntimeException::class);
            }
        } catch (Throwable $cleanupException) {
            $this->logCleanupFailure($tempPath, $cleanupException::class);
        }
    }

    private function closeOpenedZip(?ZipArchive $zip, bool $zipOpened, string $tempPath): void
    {
        if (! $zipOpened || ! $zip instanceof ZipArchive) {
            return;
        }

        try {
            if ($zip->close() === false) {
                $this->logCleanupFailure($tempPath, RuntimeException::class);
            }
        } catch (Throwable $cleanupException) {
            $this->logCleanupFailure($tempPath, $cleanupException::class);
        }
    }

    private function closeXmlReader(XMLReader $reader): void
    {
        try {
            $reader->close();
        } catch (Throwable $cleanupException) {
            Log::warning('Blueprint import structure XML reader cleanup failed.', [
                'exception' => $cleanupException::class,
            ]);
        }
    }

    private function deleteTemporaryFile(string $tempPath): void
    {
        if (! is_file($tempPath)) {
            return;
        }

        try {
            if (! unlink($tempPath)) {
                $this->logCleanupFailure($tempPath, RuntimeException::class);
            }
        } catch (Throwable $cleanupException) {
            $this->logCleanupFailure($tempPath, $cleanupException::class);
        }
    }

    private function logCleanupFailure(string $tempPath, string $exceptionClass): void
    {
        Log::warning('Blueprint import structure extraction temporary file cleanup failed.', [
            'basename' => basename($tempPath),
            'exception' => $exceptionClass,
        ]);
    }
}
