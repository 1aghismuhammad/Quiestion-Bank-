<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class McqValidationResult
{
    /**
     * @param  list<ValidatedMcqQuestion>  $valid
     * @param  list<string>  $invalidReasons
     */
    public function __construct(
        public array $valid,
        public array $invalidReasons,
    ) {}

    public function validCount(): int
    {
        return count($this->valid);
    }
}
