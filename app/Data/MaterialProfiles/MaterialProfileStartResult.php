<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

use App\Enums\MaterialProfileStartOutcome;
use App\Models\MaterialProfileVersion;

final readonly class MaterialProfileStartResult
{
    public function __construct(
        public MaterialProfileVersion $version,
        public MaterialProfileStartOutcome $outcome,
        public ?MaterialProfileStepDispatch $dispatch = null,
    ) {}

    public function wasReused(): bool
    {
        return $this->outcome === MaterialProfileStartOutcome::Reused;
    }

    public function wasCreated(): bool
    {
        return $this->outcome === MaterialProfileStartOutcome::Created;
    }
}
