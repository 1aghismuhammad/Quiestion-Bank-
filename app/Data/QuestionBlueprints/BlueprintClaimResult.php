<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

use App\Enums\BlueprintClaimOutcome;

final readonly class BlueprintClaimResult
{
    public function __construct(public BlueprintClaimOutcome $outcome) {}

    public function shouldRun(): bool
    {
        return $this->outcome->shouldRun();
    }

    public static function of(BlueprintClaimOutcome $outcome): self
    {
        return new self($outcome);
    }
}
