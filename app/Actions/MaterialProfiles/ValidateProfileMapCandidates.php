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
 * the canonical chunk core. Anything that cannot be proven against the core is
 * rejected, and one invalid candidate rejects the complete response.
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

            // Offsets are core-relative, so a negative start is the only way to
            // reference the preceding overlap and is always rejected.
            if ($start < 0) {
                throw new MaterialProfileCandidateValidationException('Evidence starts before the canonical core.');
            }

            if ($end <= $start) {
                throw new MaterialProfileCandidateValidationException('Evidence end is not after evidence start.');
            }

            if ($end > $coreLength) {
                throw new MaterialProfileCandidateValidationException('Evidence ends beyond the canonical core.');
            }

            if (($end - $start) > $maxEvidence) {
                throw new MaterialProfileCandidateValidationException('Evidence exceeds the safe length limit.');
            }

            if (! is_string($candidate->evidenceExcerpt)) {
                throw new MaterialProfileCandidateValidationException('Evidence excerpt is not a string.');
            }

            $expected = mb_substr($coreText, $start, $end - $start, 'UTF-8');

            if ($candidate->evidenceExcerpt !== $expected) {
                throw new MaterialProfileCandidateValidationException('Evidence excerpt does not match the canonical core.');
            }

            $canonicalStart = $coreCharStart + $start;
            $canonicalEnd = $coreCharStart + $end;

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
