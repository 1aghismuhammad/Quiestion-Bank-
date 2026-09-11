<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Generations;

use App\Support\Generations\UsageSubjectXor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UsageSubjectXorTest extends TestCase
{
    public function test_legacy_subject_is_valid(): void
    {
        UsageSubjectXor::assert(12, null);
        $this->assertTrue(true);
    }

    public function test_run_subject_is_valid(): void
    {
        UsageSubjectXor::assert(null, 9);
        $this->assertTrue(true);
    }

    public function test_both_subjects_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UsageSubjectXor::assert(1, 2);
    }

    public function test_neither_subject_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UsageSubjectXor::assert(null, null);
    }
}
