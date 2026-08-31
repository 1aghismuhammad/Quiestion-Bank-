<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class McqQuestionCandidate
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public mixed $question,
        public mixed $options,
        public mixed $correctAnswer,
        public mixed $explanation,
    ) {}
}
