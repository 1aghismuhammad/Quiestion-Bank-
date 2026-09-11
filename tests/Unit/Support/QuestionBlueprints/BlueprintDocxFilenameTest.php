<?php

declare(strict_types=1);

namespace Tests\Unit\Support\QuestionBlueprints;

use App\Support\QuestionBlueprints\BlueprintDocxFilename;
use PHPUnit\Framework\TestCase;

class BlueprintDocxFilenameTest extends TestCase
{
    public function test_filename_is_safe_and_bounded(): void
    {
        $name = BlueprintDocxFilename::for('Kisi/../kisi\\judul'.str_repeat('x', 200));

        $this->assertStringStartsWith('Kisi-Kisi-', $name);
        $this->assertStringEndsWith('.docx', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertLessThanOrEqual(120, strlen($name));
    }
}
