<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class PresentedMcqQuestion
{
    /**
     * @param  array<string, string>  $options
     * @param  array<string, string>  $canonicalToDisplayed
     */
    public function __construct(
        public int $number,
        public int $childIndex,
        public int $originalIndex,
        public string $question,
        public array $options,
        public string $correctAnswer,
        public string $explanation,
        public array $canonicalToDisplayed,
    ) {}
}
