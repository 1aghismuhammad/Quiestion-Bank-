<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Generations;

use App\Support\Generations\RunItemSpanWindows;
use PHPUnit\Framework\TestCase;

class RunItemSpanWindowsTest extends TestCase
{
    public function test_overlapping_same_chunk_windows_merge_once(): void
    {
        $content = str_repeat('a', 10).str_repeat('b', 10).str_repeat('c', 10);
        $windows = [
            ['char_start' => 0, 'char_end' => 16, 'profile_chunk_id' => 4, 'rank' => 1],
            ['char_start' => 8, 'char_end' => 24, 'profile_chunk_id' => 4, 'rank' => 2],
        ];

        $merged = RunItemSpanWindows::mergeOverlappingSameChunk($windows);

        $this->assertCount(1, $merged);
        $this->assertSame(0, $merged[0]['char_start']);
        $this->assertSame(24, $merged[0]['char_end']);
        $this->assertSame(mb_substr($content, 0, 24, 'UTF-8'), RunItemSpanWindows::join($content, $windows));
        $this->assertSame(
            RunItemSpanWindows::join($content, array_reverse($windows)),
            RunItemSpanWindows::join($content, $windows),
        );
    }

    public function test_adjacent_touching_windows_stay_separate(): void
    {
        $content = str_repeat('A', 8).str_repeat('B', 8);
        $windows = [
            ['char_start' => 0, 'char_end' => 8, 'profile_chunk_id' => 1, 'rank' => 1],
            ['char_start' => 8, 'char_end' => 16, 'profile_chunk_id' => 1, 'rank' => 2],
        ];

        $merged = RunItemSpanWindows::mergeOverlappingSameChunk($windows);

        $this->assertCount(2, $merged);
        $this->assertSame(str_repeat('A', 8)."\n\n".str_repeat('B', 8), RunItemSpanWindows::join($content, $windows));
        $this->assertSame(18, mb_strlen(RunItemSpanWindows::join($content, $windows), 'UTF-8'));
    }
}
