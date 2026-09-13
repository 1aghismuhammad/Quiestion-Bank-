<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class GenerationRunMcqPresentation
{
    /**
     * @param  list<PresentedMcqQuestion>  $questions
     */
    public function __construct(
        public array $questions,
        public bool $shuffleQuestions,
        public bool $shuffleOptions,
        public string $questionOrderLabel,
        public string $optionOrderLabel,
    ) {}
}
