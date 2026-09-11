<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

use App\Enums\MaterialProfileEligibilityReason;

/**
 * Read-only owner presentation of pre-start eligibility. Deliberately carries
 * no Material content, no tokens, and no workflow identifiers.
 */
final readonly class MaterialProfileEligibility
{
    public function __construct(
        public MaterialProfileEligibilityReason $reason,
        public ?string $message = null,
    ) {}

    public function isEligible(): bool
    {
        return $this->reason === MaterialProfileEligibilityReason::Eligible;
    }
}
