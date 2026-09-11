<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Profile;

use App\Actions\MaterialProfiles\ValidateProfileMapCandidates;
use App\Data\MaterialProfiles\ExtractedProfileCandidate;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\MaterialProfiles\MaterialProfileCandidateValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidateProfileMapCandidatesTest extends TestCase
{
    public function test_exact_offsets_and_excerpt_still_pass(): void
    {
        $core = 'Fotosintesis adalah proses tumbuhan.';
        $elements = $this->validator()->handle(
            [$this->candidate('Fotosintesis', 0, 12)],
            $core,
            40,
            1,
            17,
        );

        $this->assertCount(1, $elements);
        $this->assertSame('Fotosintesis', $elements[0]->evidenceExcerpt);
        $this->assertSame(40, $elements[0]->charStart);
        $this->assertSame(52, $elements[0]->charEnd);
        $this->assertSame('core-1:40-52', $elements[0]->evidenceLocator);
        $this->assertSame(MaterialProfileElementOrigin::EXTRACTED, $elements[0]->origin);
        $this->assertSame(17, $elements[0]->sourceChunkId);
    }

    public function test_incorrect_integer_offsets_with_one_exact_core_occurrence_are_reconciled(): void
    {
        $core = 'Fotosintesis adalah proses tumbuhan.';
        $elements = $this->validator()->handle(
            [$this->candidate('Fotosintesis', 3, 9)],
            $core,
            10,
            0,
            8,
        );

        $this->assertCount(1, $elements);
        $this->assertSame('Fotosintesis', $elements[0]->evidenceExcerpt);
        $this->assertSame(10, $elements[0]->charStart);
        $this->assertSame(22, $elements[0]->charEnd);
        $this->assertSame('core-0:10-22', $elements[0]->evidenceLocator);
    }

    public function test_reconciliation_uses_utf8_emoji_code_point_offsets(): void
    {
        $core = 'Bab 😀 satu: kalor dan suhu 😀 pada zat.';
        $start = mb_strpos($core, 'kalor', 0, 'UTF-8');
        $this->assertIsInt($start);

        $elements = $this->validator()->handle(
            [new ExtractedProfileCandidate(
                MaterialProfileElementKind::TOPIC->value,
                'Kalor',
                'kalor dan suhu',
                0,
                4,
            )],
            $core,
            0,
            0,
            3,
        );

        $end = $start + mb_strlen('kalor dan suhu', 'UTF-8');
        $this->assertSame($start, $elements[0]->charStart);
        $this->assertSame($end, $elements[0]->charEnd);
        $this->assertSame('kalor dan suhu', $elements[0]->evidenceExcerpt);
        $this->assertNotSame(strpos($core, 'kalor'), $elements[0]->charStart);
    }

    public function test_excerpt_present_only_outside_the_core_is_rejected(): void
    {
        $this->expectException(MaterialProfileCandidateValidationException::class);

        $this->validator()->handle(
            [new ExtractedProfileCandidate(
                MaterialProfileElementKind::TOPIC->value,
                'Overlap',
                'teks overlap saja',
                0,
                9,
            )],
            'Inti kanonik tanpa kutipan overlap.',
            20,
            1,
            4,
        );
    }

    public function test_absent_excerpt_is_rejected(): void
    {
        $this->expectException(MaterialProfileCandidateValidationException::class);

        $this->validator()->handle(
            [$this->candidate('teks yang tidak ada di inti', 0, 10)],
            'Materi ajar tentang klasifikasi makhluk hidup.',
            0,
            0,
            1,
        );
    }

    public function test_ambiguous_repeated_excerpt_with_incorrect_offsets_is_rejected(): void
    {
        $this->expectException(MaterialProfileCandidateValidationException::class);

        $this->validator()->handle(
            [$this->candidate('air', 0, 1)],
            'air tanah dan air laut',
            0,
            0,
            1,
        );
    }

    public function test_overlapping_repeated_occurrences_are_counted_and_rejected_when_offsets_miss(): void
    {
        $this->expectException(MaterialProfileCandidateValidationException::class);

        $this->validator()->handle(
            [$this->candidate('aa', 9, 11)],
            'aaa',
            0,
            0,
            1,
        );
    }

    public function test_repeated_excerpt_with_already_correct_offsets_remains_valid(): void
    {
        $core = 'air tanah dan air laut';
        $second = (int) mb_strpos($core, 'air', 1, 'UTF-8');

        $elements = $this->validator()->handle(
            [$this->candidate('air', $second, $second + 3)],
            $core,
            5,
            2,
            9,
        );

        $this->assertCount(1, $elements);
        $this->assertSame(5 + $second, $elements[0]->charStart);
        $this->assertSame(5 + $second + 3, $elements[0]->charEnd);
        $this->assertSame('air', $elements[0]->evidenceExcerpt);
    }

    public function test_overlapping_occurrence_with_already_correct_offsets_remains_valid(): void
    {
        $elements = $this->validator()->handle(
            [$this->candidate('aa', 1, 3)],
            'aaa',
            0,
            0,
            1,
        );

        $this->assertSame(1, $elements[0]->charStart);
        $this->assertSame(3, $elements[0]->charEnd);
        $this->assertSame('aa', $elements[0]->evidenceExcerpt);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unnormalizedExcerptProvider(): iterable
    {
        yield 'case difference' => ['fotosintesis'];
        yield 'leading space' => [' Fotosintesis'];
        yield 'double trailing space' => ['Fotosintesis  '];
        yield 'punctuation difference' => ['Fotosintesis.'];
    }

    #[DataProvider('unnormalizedExcerptProvider')]
    public function test_whitespace_case_and_punctuation_are_not_normalized(string $excerpt): void
    {
        $this->expectException(MaterialProfileCandidateValidationException::class);

        $this->validator()->handle(
            [new ExtractedProfileCandidate(
                MaterialProfileElementKind::TOPIC->value,
                'Fotosintesis',
                $excerpt,
                0,
                12,
            )],
            'Fotosintesis adalah proses tumbuhan.',
            0,
            0,
            1,
        );
    }

    public function test_oversized_evidence_is_rejected_even_when_unique(): void
    {
        config(['material_profile.max_evidence_chars' => 8]);
        $core = 'Fotosintesis adalah proses tumbuhan.';

        $this->expectException(MaterialProfileCandidateValidationException::class);

        $this->validator()->handle(
            [$this->candidate('Fotosintesis', 0, 12)],
            $core,
            0,
            0,
            1,
        );
    }

    public function test_malformed_offsets_are_not_reconciled_even_when_the_excerpt_is_unique(): void
    {
        $this->expectException(MaterialProfileCandidateValidationException::class);

        $this->validator()->handle(
            [new ExtractedProfileCandidate(
                MaterialProfileElementKind::TOPIC->value,
                'Fotosintesis',
                'Fotosintesis',
                'nol',
                12,
            )],
            'Fotosintesis adalah proses tumbuhan.',
            0,
            0,
            1,
        );
    }

    public function test_out_of_range_integer_offsets_reconcile_a_unique_excerpt(): void
    {
        $core = 'Fotosintesis adalah proses tumbuhan.';
        $elements = $this->validator()->handle(
            [$this->candidate('Fotosintesis', 0, mb_strlen($core, 'UTF-8') + 5)],
            $core,
            0,
            0,
            1,
        );

        $this->assertSame(0, $elements[0]->charStart);
        $this->assertSame(12, $elements[0]->charEnd);
    }

    public function test_one_invalid_candidate_rejects_the_complete_response(): void
    {
        $core = 'Fotosintesis adalah proses tumbuhan.';

        $this->expectException(MaterialProfileCandidateValidationException::class);

        $this->validator()->handle(
            [
                $this->candidate('Fotosintesis', 0, 12),
                $this->candidate('teks yang tidak ada', 0, 10),
            ],
            $core,
            0,
            0,
            1,
        );
    }

    public function test_exact_duplicates_are_removed_after_reconciliation(): void
    {
        $core = 'Fotosintesis adalah proses tumbuhan.';
        $elements = $this->validator()->handle(
            [
                $this->candidate('Fotosintesis', 0, 12),
                $this->candidate('Fotosintesis', 99, 111),
            ],
            $core,
            0,
            0,
            1,
        );

        $this->assertCount(1, $elements);
        $this->assertSame(0, $elements[0]->charStart);
        $this->assertSame(12, $elements[0]->charEnd);
    }

    private function validator(): ValidateProfileMapCandidates
    {
        return new ValidateProfileMapCandidates;
    }

    private function candidate(string $excerpt, mixed $start, mixed $end): ExtractedProfileCandidate
    {
        return new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            'Topik',
            $excerpt,
            $start,
            $end,
        );
    }
}
