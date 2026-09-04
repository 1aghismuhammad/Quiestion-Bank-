<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

/**
 * Untrusted map output. Every field stays `mixed` until server-side validation
 * has proven the kind, the text, and the core-relative evidence offsets.
 */
final readonly class ExtractedProfileCandidate
{
    public function __construct(
        public mixed $kind,
        public mixed $text,
        public mixed $evidenceExcerpt,
        public mixed $evidenceStart,
        public mixed $evidenceEnd,
    ) {}
}
