<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

use App\Enums\MaterialProfileElementKind;

/**
 * Bounded summary of one validated extracted element. Carries no Material text:
 * only the normalized observation, a safe locator, and canonical offsets.
 */
final readonly class ProfileElementSummary
{
    public function __construct(
        public MaterialProfileElementKind $kind,
        public string $text,
        public ?string $evidenceLocator = null,
        public ?int $charStart = null,
        public ?int $charEnd = null,
    ) {}
}
