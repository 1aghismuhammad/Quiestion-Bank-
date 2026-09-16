<?php

declare(strict_types=1);

namespace Tests\Unit\Support\QuestionSets;

use App\Services\QuestionSets\QuestionSetDocxWriter;
use App\Support\QuestionSets\QuestionSetDocxSaver;
use App\Support\QuestionSets\QuestionSetDocxTempFile;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QuestionSetDocxCleanupTest extends TestCase
{
    public function test_successful_generation_keeps_the_docx_for_the_response(): void
    {
        $writer = new QuestionSetDocxWriter;
        $phpWord = new PhpWord;
        $phpWord->addSection()->addText('Soal');
        $response = $writer->stream($phpWord, 'Soal-tes.docx');

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $path = $response->getFile()->getPathname();
        $this->assertFileExists($path);
        $this->assertTrue($response->headers->get('content-disposition') !== null);
        $this->assertStringNotContainsString(sys_get_temp_dir(), (string) $response->headers->get('content-disposition'));
        $this->assertTrue((new \ReflectionProperty(BinaryFileResponse::class, 'deleteFileAfterSend'))->getValue($response));

        @unlink($path);
    }

    public function test_rename_failure_deletes_the_raw_temporary_file(): void
    {
        $temp = new class extends QuestionSetDocxTempFile
        {
            public ?string $raw = null;

            public function createRaw(): string
            {
                $this->raw = parent::createRaw();

                return $this->raw;
            }

            public function moveToDocx(string $rawPath): string
            {
                throw new RuntimeException('Unable to prepare a temporary DOCX file.');
            }
        };

        $writer = new QuestionSetDocxWriter($temp, new class implements QuestionSetDocxSaver
        {
            public function save(PhpWord $phpWord, string $path): void {}
        });

        try {
            $writer->stream(new PhpWord, 'Soal-tes.docx');
            $this->fail('Rename failure must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to prepare a temporary DOCX file.', $exception->getMessage());
        }

        $this->assertNotNull($temp->raw);
        $this->assertFileDoesNotExist($temp->raw);
        $this->assertFileDoesNotExist($temp->raw.'.docx');
    }

    public function test_response_construction_failure_deletes_the_docx_path(): void
    {
        $captured = (object) ['path' => null];
        $writer = new class($captured) extends QuestionSetDocxWriter
        {
            public function __construct(private object $captured)
            {
                parent::__construct();
            }

            protected function createAttachmentResponse(string $docx, string $filename): BinaryFileResponse
            {
                $this->captured->path = $docx;

                throw new RuntimeException('Unable to build the DOCX response.');
            }
        };

        $phpWord = new PhpWord;
        $phpWord->addSection()->addText('Soal');

        try {
            $writer->stream($phpWord, 'Soal-tes.docx');
            $this->fail('Response construction failure must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to build the DOCX response.', $exception->getMessage());
        }

        $this->assertNotNull($captured->path);
        $this->assertFileDoesNotExist($captured->path);
    }

    public function test_save_failure_deletes_the_docx_path(): void
    {
        $captured = (object) ['path' => null];
        $saver = new class($captured) implements QuestionSetDocxSaver
        {
            public function __construct(private object $captured) {}

            public function save(PhpWord $phpWord, string $path): void
            {
                $this->captured->path = $path;

                throw new RuntimeException('Unable to write the DOCX file.');
            }
        };

        $writer = new QuestionSetDocxWriter(new QuestionSetDocxTempFile, $saver);

        try {
            $writer->stream(new PhpWord, 'Soal-tes.docx');
            $this->fail('Save failure must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the DOCX file.', $exception->getMessage());
        }

        $this->assertNotNull($captured->path);
        $this->assertFileDoesNotExist($captured->path);
        $this->assertStringNotContainsString('secret', $captured->path);
    }
}
