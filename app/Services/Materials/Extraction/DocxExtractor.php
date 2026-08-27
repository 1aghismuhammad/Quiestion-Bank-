<?php

declare(strict_types=1);

namespace App\Services\Materials\Extraction;

use App\Contracts\Materials\MaterialContentExtractor;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

class DocxExtractor implements MaterialContentExtractor
{
    public const MAX_ZIP_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    public const MAX_ZIP_COMPRESSION_RATIO = 100;

    public const MAX_DOCUMENT_XML_BYTES = 20 * 1024 * 1024;

    private const DOCUMENT_XML_NAME = 'word/document.xml';

    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const TEMP_PREFIX = 'mtx';

    public function __construct(private ?string $temporaryDirectory = null) {}

    public function extract(string $contents): string
    {
        $tempPath = tempnam($this->temporaryDirectory(), self::TEMP_PREFIX);

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary material file.');
        }

        $handle = null;
        $zip = null;
        $zipOpened = false;

        try {
            $handle = fopen($tempPath, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open a temporary material file.');
            }

            $this->writeAll($handle, $contents);

            if (fclose($handle) === false) {
                $handle = null;

                throw new RuntimeException('Unable to close a temporary material file.');
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

            if (strlen($xml) > self::MAX_DOCUMENT_XML_BYTES) {
                throw new UnrecoverableMaterialExtractionException('word/document.xml exceeds the size limit.');
            }

            return $this->extractTextFromDocumentXml($xml);
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
                throw new RuntimeException('Incomplete material temporary file write.');
            }

            $totalWritten += $written;
        }

        if ($totalWritten !== $length) {
            throw new RuntimeException('Incomplete material temporary file write.');
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

            if ($uncompressed > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                throw new UnrecoverableMaterialExtractionException('DOCX archive exceeds ZIP security limits.');
            }

            if ($this->isDocumentXmlName((string) $stat['name'])) {
                if ($uncompressed > self::MAX_DOCUMENT_XML_BYTES) {
                    throw new UnrecoverableMaterialExtractionException('word/document.xml exceeds the size limit.');
                }

                $documentXmlIndex = $index;
            }

            $totalUncompressed += $uncompressed;
            $totalCompressed += $compressed;

            if ($totalUncompressed > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                throw new UnrecoverableMaterialExtractionException('DOCX archive exceeds ZIP security limits.');
            }
        }

        if ($totalCompressed === 0 && $totalUncompressed > 0) {
            throw new UnrecoverableMaterialExtractionException('DOCX archive exceeds ZIP security limits.');
        }

        if ($totalCompressed === 0 && $totalUncompressed === 0) {
            throw new UnrecoverableMaterialExtractionException('DOCX archive metadata is unusable.');
        }

        if (($totalUncompressed / $totalCompressed) > self::MAX_ZIP_COMPRESSION_RATIO) {
            throw new UnrecoverableMaterialExtractionException('DOCX archive exceeds ZIP security limits.');
        }

        if ($documentXmlIndex === null) {
            throw new UnrecoverableMaterialExtractionException('DOCX archive is missing word/document.xml.');
        }

        return $documentXmlIndex;
    }

    private function extractTextFromDocumentXml(string $xml): string
    {
        $reader = new XMLReader;

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

            $text = '';

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    if ($this->isWordElement($reader, 't')) {
                        $chunk = $reader->readString();

                        if ($chunk === false) {
                            throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.');
                        }

                        $text .= $chunk;

                        continue;
                    }

                    if ($this->isWordElement($reader, 'tab')) {
                        $text .= "\t";

                        continue;
                    }

                    if ($this->isWordElement($reader, 'br')) {
                        $text .= "\n";

                        continue;
                    }
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $this->isWordElement($reader, 'p')) {
                    $text .= "\n";
                }
            }

            return $text;
        } catch (UnrecoverableMaterialExtractionException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new UnrecoverableMaterialExtractionException('DOCX XML could not be parsed.', 0, $exception);
        } finally {
            $this->closeXmlReader($reader);
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
            Log::warning('Material extraction XML reader cleanup failed.', [
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
        Log::warning('Material extraction temporary file cleanup failed.', [
            'basename' => basename($tempPath),
            'exception' => $exceptionClass,
        ]);
    }
}
