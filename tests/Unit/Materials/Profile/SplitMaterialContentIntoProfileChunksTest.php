<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Profile;

use App\Data\MaterialProfiles\ProfileChunkSplit;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Services\Materials\Profile\SplitMaterialContentIntoProfileChunks;
use App\Support\Materials\MaterialContentHasher;
use Tests\TestCase;

class SplitMaterialContentIntoProfileChunksTest extends TestCase
{
    private SplitMaterialContentIntoProfileChunks $splitter;

    private MaterialContentHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasher = new MaterialContentHasher;
        $this->splitter = new SplitMaterialContentIntoProfileChunks($this->hasher);
    }

    public function test_empty_content_is_rejected(): void
    {
        $this->expectException(MaterialProfileRejectedException::class);

        $this->splitter->handle('');
    }

    public function test_content_shorter_than_one_core_is_a_single_chunk(): void
    {
        $content = 'Pendidikan adalah usaha sadar.';
        $chunks = $this->splitter->handle($content);

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]->charStart);
        $this->assertSame(mb_strlen($content, 'UTF-8'), $chunks[0]->charEnd);
        $this->assertNull($chunks[0]->overlapBeforeStart);
        $this->assertSame($this->coreHash($content, 0, $chunks[0]->charEnd), $chunks[0]->coreTextHash);
        $this->assertSame($chunks[0]->coreLength(), $chunks[0]->charEnd - $chunks[0]->charStart);
    }

    public function test_exact_core_limit_is_one_chunk(): void
    {
        $content = str_repeat('a', 12_000);
        $chunks = $this->splitter->handle($content);

        $this->assertCount(1, $chunks);
        $this->assertSame(12_000, $chunks[0]->coreLength());
    }

    public function test_one_past_core_limit_creates_two_cores_with_overlap(): void
    {
        $content = str_repeat('b', 12_001);
        $chunks = $this->splitter->handle($content);

        $this->assertCount(2, $chunks);
        $this->assertSame($content, $this->reconstructCores($content, $chunks));
        $this->assertSame(0, $chunks[0]->charStart);
        $this->assertSame(12_000, $chunks[0]->charEnd);
        $this->assertSame(12_000, $chunks[1]->charStart);
        $this->assertSame(12_001, $chunks[1]->charEnd);
        $this->assertSame(11_600, $chunks[1]->overlapBeforeStart);
        $this->assertSame(12_000, $chunks[1]->overlapBeforeEnd);
        $this->assertSame(400, $chunks[1]->overlapBeforeEnd - $chunks[1]->overlapBeforeStart);
        $this->assertSame($this->coreHash($content, 12_000, 1), $chunks[1]->coreTextHash);
    }

    public function test_exact_max_canonical_content_produces_twenty_hard_cores(): void
    {
        $content = str_repeat("x\n", 120_000);
        $this->assertSame(240_000, mb_strlen($content, 'UTF-8'));

        $chunks = $this->splitter->handle($content);

        $this->assertCount(20, $chunks);
        $this->assertSame($content, $this->reconstructCores($content, $chunks));
        $this->assertSame(0, $chunks[0]->charStart);
        $this->assertSame(240_000, $chunks[19]->charEnd);

        foreach ($chunks as $chunk) {
            $this->assertSame(12_000, $chunk->coreLength());
            $this->assertSame(
                $this->coreHash($content, $chunk->charStart, $chunk->coreLength()),
                $chunk->coreTextHash,
            );
        }
    }

    public function test_one_past_max_canonical_content_is_rejected(): void
    {
        $this->expectException(MaterialProfileRejectedException::class);

        $this->splitter->handle(str_repeat('a', 240_001));
    }

    public function test_indonesian_accented_emoji_and_punctuation_use_utf8_code_points(): void
    {
        $content = "Pendidikan naïve café 😀 — usaha sadar.\n\nTujuan: indikator.";
        $chunks = $this->splitter->handle($content);
        $length = mb_strlen($content, 'UTF-8');

        $this->assertCount(1, $chunks);
        $this->assertSame($length, $chunks[0]->charEnd);
        $this->assertSame(
            mb_substr($content, 0, $length, 'UTF-8'),
            mb_substr($content, $chunks[0]->charStart, $chunks[0]->coreLength(), 'UTF-8'),
        );
        $this->assertStringContainsString('😀', mb_substr($content, $chunks[0]->charStart, $chunks[0]->coreLength(), 'UTF-8'));
    }

    public function test_preferred_boundaries_do_not_reject_valid_max_length_content(): void
    {
        $content = str_repeat("a\n\n", 80_000);
        $this->assertSame(240_000, mb_strlen($content, 'UTF-8'));

        $chunks = $this->splitter->handle($content);

        $this->assertLessThanOrEqual(20, count($chunks));
        $this->assertSame(240_000, $chunks[array_key_last($chunks)]->charEnd);
        $this->assertSame($content, $this->reconstructCores($content, $chunks));
    }

    public function test_preferred_underfill_falls_back_to_hard_twelve_thousand_cores(): void
    {
        $content = str_repeat(str_repeat('x', 9_999)."\n", 24);
        $this->assertSame(240_000, mb_strlen($content, 'UTF-8'));

        $chunks = $this->splitter->handle($content);

        $this->assertCount(20, $chunks);
        $this->assertSame($content, $this->reconstructCores($content, $chunks));

        foreach ($chunks as $chunk) {
            $this->assertSame(12_000, $chunk->coreLength());
        }
    }

    public function test_splitter_hash_is_stable_across_calls(): void
    {
        $content = "Pendidikan naïve café 😀.\n\n".str_repeat('indikator ', 2_000);
        $first = $this->splitter->handle($content);
        $second = $this->splitter->handle($content);

        $this->assertSame(
            array_map(fn ($chunk): array => [
                $chunk->chunkIndex,
                $chunk->charStart,
                $chunk->charEnd,
                $chunk->overlapBeforeStart,
                $chunk->overlapBeforeEnd,
                $chunk->coreTextHash,
            ], $first),
            array_map(fn ($chunk): array => [
                $chunk->chunkIndex,
                $chunk->charStart,
                $chunk->charEnd,
                $chunk->overlapBeforeStart,
                $chunk->overlapBeforeEnd,
                $chunk->coreTextHash,
            ], $second),
        );
    }

    public function test_reconstruction_matches_stored_core_hash(): void
    {
        $content = str_repeat('あ', 12_400);
        $chunks = $this->splitter->handle($content);

        foreach ($chunks as $chunk) {
            $core = mb_substr($content, $chunk->charStart, $chunk->coreLength(), 'UTF-8');
            $this->assertSame($chunk->coreTextHash, $this->hasher->hash($core));
            $this->assertSame(mb_strlen($core, 'UTF-8'), $chunk->coreLength());
        }
    }

    /**
     * @param  list<ProfileChunkSplit>  $chunks
     */
    private function reconstructCores(string $content, array $chunks): string
    {
        $rebuilt = '';

        foreach ($chunks as $chunk) {
            $rebuilt .= mb_substr($content, $chunk->charStart, $chunk->coreLength(), 'UTF-8');
        }

        return $rebuilt;
    }

    private function coreHash(string $content, int $start, int $length): string
    {
        return $this->hasher->hash(mb_substr($content, $start, $length, 'UTF-8'));
    }
}
