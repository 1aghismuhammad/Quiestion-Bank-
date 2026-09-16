<?php

declare(strict_types=1);

namespace Tests\Unit\Support\QuestionSets;

use App\Support\QuestionSets\QuestionSetDocxFilename;
use PHPUnit\Framework\TestCase;

class QuestionSetDocxFilenameTest extends TestCase
{
    public function test_student_filename_is_safe_and_bounded(): void
    {
        $name = QuestionSetDocxFilename::for('Soal/../ujian\\judul'.str_repeat('x', 200), false);

        $this->assertStringStartsWith('Soal-', $name);
        $this->assertStringEndsWith('.docx', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertLessThanOrEqual(120, strlen($name));
    }

    public function test_teacher_filename_uses_kunci_prefix(): void
    {
        $name = QuestionSetDocxFilename::for('Ujian Matematika', true);

        $this->assertSame('Soal-Kunci-Ujian-Matematika.docx', $name);
    }

    public function test_empty_title_falls_back_to_soal(): void
    {
        $this->assertSame('Soal-soal.docx', QuestionSetDocxFilename::for('   ', false));
        $this->assertSame('Soal-Kunci-soal.docx', QuestionSetDocxFilename::for('!!!', true));
    }
}
