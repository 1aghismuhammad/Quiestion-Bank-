<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

final readonly class ProfileMapResult
{
    /**
     * @param  list<ExtractedProfileCandidate>  $candidates
     */
    public function __construct(
        public array $candidates,
        public ProfileProviderAttemptMetadata $metadata,
    ) {}
}
