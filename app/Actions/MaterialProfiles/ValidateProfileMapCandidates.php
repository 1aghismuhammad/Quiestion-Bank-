<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\ExtractedProfileCandidate;
use App\Data\MaterialProfiles\ValidatedProfileElement;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\MaterialProfiles\MaterialProfileCandidateValidationException;
use App\Support\MaterialProfiles\MaterialProfileBudgets;

/**
 * Server-side evidence validation for map output.
 *
 * Offsets arriving from the provider are UTF-8 code-point offsets relative to
 * the canonical chunk core. An exact offset hit is preserved. Incorrect integer
 * offsets may be replaced only when the excerpt occurs exactly once, character
 * for character, inside the core. Overlap is never searched. One invalid
 * candidate rejects the complete response.
 */
class ValidateProfileMapCandidates
{
    use NormalizesProfileCandidateText;

    /**
     * @param  list<ExtractedProfileCandidate>  $candidates
     * @return list<ValidatedProfileElement>
     */
    public function handle(
        array $candidates,
        string $coreText,
        int $coreCharStart,
        int $chunkIndex,
        int $sourceChunkId,
    ): array {
        $maxCandidates = MaterialProfileBudgets::maxMapCandidates();

        if (count($candidates) > $maxCandidates) {
            throw new MaterialProfileCandidateValidationException('Map candidate count exceeds the limit.');
        }

        $coreLength = mb_strlen($coreText, 'UTF-8');
        $maxEvidence = max(1, (int) config('material_profile.max_evidence_chars', 500));
        $validated = [];

        foreach ($candidates as $candidate) {
            $kind = $this->supportedCandidateKind($candidate->kind);
            $text = $this->normalizedCandidateText($candidate->text);
            $start = $this->candidateInteger($candidate->evidenceStart);
            $end = $this->candidateInteger($candidate->evidenceEnd);

            if (! is_string($candidate->evidenceExcerpt)) {
                throw new MaterialProfileCandidateValidationException('Evidence excerpt is not a string.');
            }

            $excerpt = $candidate->evidenceExcerpt;
            $excerptLength = mb_strlen($excerpt, 'UTF-8');

            if ($excerptLength === 0) {
                throw new MaterialProfileCandidateValidationException('Evidence excerpt is empty.');
            }

            if ($excerptLength > $maxEvidence) {
                throw new MaterialProfileCandidateValidationException('Evidence exceeds the safe length limit.');
            }

            [$relativeStart, $relativeEnd] = $this->relativeCoreOffsets(
                $coreText,
                $coreLength,
                $excerpt,
                $start,
                $end,
                $maxEvidence,
            );

            $expected = mb_substr($coreText, $relativeStart, $relativeEnd - $relativeStart, 'UTF-8');
            $canonicalStart = $coreCharStart + $relativeStart;
            $canonicalEnd = $coreCharStart + $relativeEnd;

            $validated[] = new ValidatedProfileElement(
                kind: $kind,
                text: $text,
                origin: MaterialProfileElementOrigin::EXTRACTED,
                sourceChunkId: $sourceChunkId,
                evidenceExcerpt: $expected,
                evidenceLocator: self::evidenceLocator($chunkIndex, $canonicalStart, $canonicalEnd),
                charStart: $canonicalStart,
                charEnd: $canonicalEnd,
            );
        }

        return $this->withoutExactDuplicates($validated);
    }

    public static function evidenceLocator(int $chunkIndex, int $charStart, int $charEnd): string
    {
        return 'core-'.$chunkIndex.':'.$charStart.'-'.$charEnd;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function relativeCoreOffsets(
        string $coreText,
        int $coreLength,
        string $excerpt,
        int $start,
        int $end,
        int $maxEvidence,
    ): array {
        if ($this->offsetsIdentifyExactSlice($coreText, $coreLength, $excerpt, $start, $end, $maxEvidence)) {
            return [$start, $end];
        }

        $starts = $this->exactCoreOccurrenceStarts($coreText, $excerpt);

        if ($starts === []) {
            throw new MaterialProfileCandidateValidationException('Evidence excerpt does not match the canonical core.');
        }

        if (count($starts) > 1) {
            throw new MaterialProfileCandidateValidationException('Evidence excerpt is ambiguous in the canonical core.');
        }

        $derivedStart = $starts[0];
        $derivedEnd = $derivedStart + mb_strlen($excerpt, 'UTF-8');

        if ($derivedStart < 0
            || $derivedEnd <= $derivedStart
            || $derivedEnd > $coreLength
            || ($derivedEnd - $derivedStart) > $maxEvidence) {
            throw new MaterialProfileCandidateValidationException('Evidence excerpt does not match the canonical core.');
        }

        return [$derivedStart, $derivedEnd];
    }

    private function offsetsIdentifyExactSlice(
        string $coreText,
        int $coreLength,
        string $excerpt,
        int $start,
        int $end,
        int $maxEvidence,
    ): bool {
        if ($start < 0 || $end <= $start || $end > $coreLength) {
            return false;
        }

        if (($end - $start) > $maxEvidence) {
            return false;
        }

        return $excerpt === mb_substr($coreText, $start, $end - $start, 'UTF-8');
    }

    /**
     * Exact, case-sensitive, character-for-character occurrences inside the
     * canonical core. The search advances one UTF-8 code point so overlapping
     * repeats such as "aa" in "aaa" are counted as two.
     *
     * @return list<int>
     */
    private function exactCoreOccurrenceStarts(string $coreText, string $excerpt): array
    {
        $excerptLength = mb_strlen($excerpt, 'UTF-8');
        $coreLength = mb_strlen($coreText, 'UTF-8');

        if ($excerptLength === 0 || $excerptLength > $coreLength) {
            return [];
        }

        $starts = [];
        $from = 0;

        while ($from <= ($coreLength - $excerptLength)) {
            $found = mb_strpos($coreText, $excerpt, $from, 'UTF-8');

            if ($found === false) {
                break;
            }

            $starts[] = $found;
            $from = $found + 1;
        }

        return $starts;
    }

    /**
     * @param  list<ValidatedProfileElement>  $elements
     * @return list<ValidatedProfileElement>
     */
    private function withoutExactDuplicates(array $elements): array
    {
        $seen = [];
        $unique = [];

        foreach ($elements as $element) {
            $key = $element->dedupeKey();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $element;
        }

        return $unique;
    }
}
