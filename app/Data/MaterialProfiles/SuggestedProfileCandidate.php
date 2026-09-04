<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

/**
 * Untrusted reduce output. The server decides origin, ordering, and identifiers.
 */
final readonly class SuggestedProfileCandidate
{
    public function __construct(
        public mixed $kind,
        public mixed $text,
    ) {}
}
