<?php

declare(strict_types=1);

namespace App\Data\Generations;

use App\Enums\QuestionType;

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
        public QuestionType $questionType = QuestionType::MULTIPLE_CHOICE,
        public ?string $modelAnswer = null,
        public ?string $rubric = null,
    ) {}
}
