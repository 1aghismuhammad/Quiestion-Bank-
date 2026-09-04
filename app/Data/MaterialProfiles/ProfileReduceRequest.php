<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

/**
 * The complete input a reduce provider call is allowed to see. Chunk cores and
 * complete Material content are structurally absent.
 */
final readonly class ProfileReduceRequest
{
    /**
     * @param  list<ProfileElementSummary>  $summaries
     */
    public function __construct(
        public int $profileVersionId,
        public array $summaries,
        public string $model,
        public string $promptVersion,
    ) {}
}
