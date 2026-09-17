<?php

declare(strict_types=1);

namespace App\Support\MaterialProfiles;

class CanonicalEvidenceLocator
{
    /**
     * Finds exact one occurrence of a provider excerpt in the core text, ignoring presentation-only whitespace.
     * Returns an array with [charStart, charEnd] relative to the core text, or an empty array if not found or ambiguous.
     *
     * @return array{0: int, 1: int}|array{}
     */
    public function locateUnique(string $coreText, string $excerpt): array
    {
        $excerptLength = mb_strlen($excerpt, 'UTF-8');
        $coreLength = mb_strlen($coreText, 'UTF-8');

        if ($excerptLength === 0 || $coreLength === 0) {
            return [];
        }

        // Normalize spaces in the excerpt to create a robust regex pattern
        // 1. Collapse all whitespaces in the excerpt to a single space
        $normalized = preg_replace('/\s+/u', ' ', $excerpt);
        if (! is_string($normalized) || trim($normalized) === '') {
            return [];
        }

        $normalized = trim($normalized);

        // 2. Quote the non-whitespace literals
        $pattern = preg_quote($normalized, '/');

        // 3. Replace the quoted spaces with a pattern that matches any whitespace sequences
        $pattern = str_replace(' ', '\s+', $pattern);

        // 4. PREG_OFFSET_CAPTURE returns byte offsets, so we need to match byte-by-byte safely.
        // We stop after 2 matches to prevent extreme performance cost.
        $matched = preg_match_all(
            '/(?=('.$pattern.'))/u',
            $coreText,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if ($matched === false || $matched === 0 || empty($matches[1])) {
            return [];
        }

        // We only accept exactly ONE canonical match
        if (count($matches[1]) !== 1) {
            return [];
        }

        $matchedString = $matches[1][0][0];
        $byteOffset = $matches[1][0][1];

        // Convert byte offsets back to UTF-8 character offsets safely
        $charStart = mb_strlen(substr($coreText, 0, $byteOffset), 'UTF-8');
        $charEnd = $charStart + mb_strlen($matchedString, 'UTF-8');

        return [$charStart, $charEnd];
    }
}
