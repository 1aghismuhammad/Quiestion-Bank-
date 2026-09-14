<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class EssayQuestionCandidate
{
    public function __construct(
        public mixed $question,
        public mixed $modelAnswer,
        public mixed $rubric,
        public mixed $explanation,
    ) {}
}
