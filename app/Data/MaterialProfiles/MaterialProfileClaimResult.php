<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

use App\Enums\MaterialProfileClaimOutcome;

final readonly class MaterialProfileClaimResult
{
    public function __construct(
        public bool $shouldRun,
        public MaterialProfileClaimOutcome $outcome,
    ) {}

    public static function of(MaterialProfileClaimOutcome $outcome): self
    {
        $shouldRun = $outcome === MaterialProfileClaimOutcome::Claimed
            || $outcome === MaterialProfileClaimOutcome::Resumed;

        return new self($shouldRun, $outcome);
    }
}
