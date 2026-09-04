<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileElementKind;
use App\Exceptions\MaterialProfiles\MaterialProfileCandidateValidationException;

trait NormalizesProfileCandidateText
{
    /**
     * Collapse control characters and runs of whitespace, then enforce the safe
     * length limit. Rejects anything that normalizes to an empty string.
     */
    private function normalizedCandidateText(mixed $value): string
    {
        if (! is_string($value)) {
            throw new MaterialProfileCandidateValidationException('Candidate text is not a string.');
        }

        $stripped = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $collapsed = preg_replace('/\s+/u', ' ', is_string($stripped) ? $stripped : '');
        $text = trim(is_string($collapsed) ? $collapsed : '');

        if ($text === '') {
            throw new MaterialProfileCandidateValidationException('Candidate text is empty.');
        }

        $maxChars = max(1, (int) config('material_profile.max_element_text_chars', 300));

        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            throw new MaterialProfileCandidateValidationException('Candidate text exceeds the safe length limit.');
        }

        return $text;
    }

    private function supportedCandidateKind(mixed $value): MaterialProfileElementKind
    {
        if (! is_string($value)) {
            throw new MaterialProfileCandidateValidationException('Candidate kind is not a string.');
        }

        $kind = MaterialProfileElementKind::tryFrom($value);

        if ($kind === null) {
            throw new MaterialProfileCandidateValidationException('Candidate kind is not supported.');
        }

        return $kind;
    }

    private function candidateInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && $value === floor($value) && abs($value) <= (float) PHP_INT_MAX) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new MaterialProfileCandidateValidationException('Candidate offset is not an integer.');
    }

    private function normalizedDedupeKey(MaterialProfileElementKind $kind, string $text): string
    {
        return $kind->value.'|'.mb_strtolower($text, 'UTF-8');
    }
}
