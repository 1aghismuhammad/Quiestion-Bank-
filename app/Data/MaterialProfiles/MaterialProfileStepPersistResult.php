<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

final readonly class MaterialProfileStepPersistResult
{
    public function __construct(
        public bool $persisted,
        public ?MaterialProfileStepDispatch $dispatch = null,
    ) {}

    public static function discarded(): self
    {
        return new self(false);
    }
}
