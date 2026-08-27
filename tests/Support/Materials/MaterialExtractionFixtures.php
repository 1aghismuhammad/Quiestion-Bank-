<?php

declare(strict_types=1);

namespace Tests\Support\Materials;

use RuntimeException;
use ZipArchive;

final class MaterialExtractionFixtures
{
    public static function utf8Txt(string $text = "Hello TXT\n"): string
    {
        return $text;
    }

    public static function utf8BomTxt(string $text = 'Hello'): string
    {
        return "\xEF\xBB\xBF".$text;
    }

    public static function utf16LeTxt(string $text = 'Hello'): string
    {
        $encoded = iconv('UTF-8', 'UTF-16LE', $text);

        if ($encoded === false) {
            throw new RuntimeException('Unable to encode UTF-16LE fixture.');
        }

        return "\xFF\xFE".$encoded;
    }

    public static function utf16BeTxt(string $text = 'Hello'): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE', $text);

        if ($encoded === false) {
            throw new RuntimeException('Unable to encode UTF-16BE fixture.');
        }

        return "\xFE\xFF".$encoded;
    }

    public static function invalidUtf8Txt(): string
    {
        return "hello\xC3\x28";
    }

    public static function nulByteTxt(): string
    {
        return "hello\0world";
    }

    public static function extractablePdf(): string
    {
        return self::pdfWithStream('BT /F1 12 Tf 72 720 Td (Hello PDF) Tj ET');
    }

    public static function emptyTextPdf(): string
    {
        return self::pdfWithStream('BT ET');
    }

    private static function pdfWithStream(string $stream): string
    {
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $header = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $body = '';
        $offsets = [];
        $position = strlen($header);

        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = $position;
            $body .= $object;
            $position += strlen($object);
        }

        $xref = 'xref'."\n".'0 6'."\n".'0000000000 65535 f '."\n";

        for ($objectId = 1; $objectId <= 5; $objectId++) {
            $xref .= sprintf('%010d 00000 n ', $offsets[$objectId])."\n";
        }

        return $header
            .$body
            .$xref
            .'trailer'."\n".'<< /Size 6 /Root 1 0 R >>'."\n"
            .'startxref'."\n".$position."\n"
            .'%%EOF'."\n";
    }

    public static function corruptPdf(): string
    {
        return "%PDF-1.4\nthis is not a valid pdf object stream";
    }

    public static function validDocx(string $bodyXml): string
    {
        return self::docxFromDocumentXml(self::documentXml($bodyXml));
    }

    public static function documentXml(string $bodyXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:xml="http://www.w3.org/XML/1998/namespace">'
            .'<w:body>'.$bodyXml.'</w:body>'
            .'</w:document>';
    }

    public static function simpleParagraphDocx(string $text = 'Hello DOCX'): string
    {
        return self::validDocx('<w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p>');
    }

    public static function semanticDocx(): string
    {
        $body = '<w:p><w:r><w:t>Hello</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>One</w:t><w:tab/><w:t>Two</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Line</w:t><w:br/><w:t>Break</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t xml:space="preserve">  spaced  </w:t></w:r></w:p>';

        return self::validDocx($body);
    }

    public static function docxMissingDocumentXml(): string
    {
        return self::zipFromFiles([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
        ]);
    }

    public static function malformedZip(): string
    {
        return "PK\x03\x04not-a-zip-archive";
    }

    public static function malformedXmlDocx(): string
    {
        return self::docxFromDocumentXml('<not-xml');
    }

    public static function highCompressionRatioDocx(): string
    {
        return self::zipFromFiles([
            'word/document.xml' => self::documentXml('<w:p><w:r><w:t>Hello</w:t></w:r></w:p>'),
            'word/padding.bin' => str_repeat("\0", 200_000),
        ]);
    }

    public static function claimedUncompressedDocx(int $claimedUncompressed): string
    {
        $documentXml = self::documentXml('<w:p><w:r><w:t>Hello</w:t></w:r></w:p>');

        return self::zipStoredEntries([
            [
                'name' => 'word/document.xml',
                'data' => $documentXml,
            ],
            [
                'name' => 'word/padding.bin',
                'data' => 'x',
                'uncompressed' => $claimedUncompressed,
                'compressed' => 1,
            ],
        ]);
    }

    public static function claimedDocumentXmlDocx(int $claimedUncompressed): string
    {
        return self::zipStoredEntries([
            [
                'name' => 'word/document.xml',
                'data' => self::documentXml('<w:p><w:r><w:t>Hello</w:t></w:r></w:p>'),
                'uncompressed' => $claimedUncompressed,
                'compressed' => 64,
            ],
        ]);
    }

    public static function encryptedDocx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fxe');

        if ($path === false) {
            throw new RuntimeException('Unable to create encrypted DOCX fixture.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open encrypted DOCX fixture.');
        }

        $zip->addFromString('word/document.xml', self::documentXml('<w:p><w:r><w:t>Secret</w:t></w:r></w:p>'));

        $encrypted = defined('ZipArchive::EM_AES_256')
            && $zip->setEncryptionName('word/document.xml', ZipArchive::EM_AES_256, 'secret');

        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        if ($encrypted && $bytes !== false) {
            return $bytes;
        }

        return self::zipStoredEntries([
            [
                'name' => 'word/document.xml',
                'data' => self::documentXml('<w:p><w:r><w:t>Secret</w:t></w:r></w:p>'),
                'flag' => 1,
            ],
        ]);
    }

    public static function docxFromDocumentXml(string $documentXml): string
    {
        return self::zipFromFiles([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
            'word/document.xml' => $documentXml,
        ]);
    }

    /**
     * @param  array<string, string>  $files
     */
    public static function zipFromFiles(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fxz');

        if ($path === false) {
            throw new RuntimeException('Unable to create ZIP fixture.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open ZIP fixture.');
        }

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        if ($bytes === false) {
            throw new RuntimeException('Unable to read ZIP fixture.');
        }

        return $bytes;
    }

    /**
     * @param  list<array{name: string, data: string, uncompressed?: int, compressed?: int, flag?: int}>  $entries
     */
    public static function zipStoredEntries(array $entries): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $data = $entry['data'];
            $crc = crc32($data) & 0xFFFFFFFF;
            $compressed = $entry['compressed'] ?? strlen($data);
            $uncompressed = $entry['uncompressed'] ?? strlen($data);
            $flag = $entry['flag'] ?? 0;
            $nameLength = strlen($name);

            $localHeader = pack('VvvvvvVVVvv', 0x04034B50, 20, $flag, 0, 0, 0, $crc, $compressed, $uncompressed, $nameLength, 0)
                .$name
                .$data;

            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $flag, 0, 0, 0, $crc, $compressed, $uncompressed, $nameLength, 0, 0, 0, 0, 0, $offset)
                .$name;

            $offset += strlen($localHeader);
            $local .= $localHeader;
            $count++;
        }

        $eocd = pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($central), strlen($local), 0);

        return $local.$central.$eocd;
    }
}
