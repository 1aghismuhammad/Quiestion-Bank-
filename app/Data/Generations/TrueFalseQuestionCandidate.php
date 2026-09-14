<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class TrueFalseQuestionCandidate
{
    public function __construct(
        public mixed $question,
        public mixed $correctAnswer,
        public mixed $explanation,
    ) {}
}
