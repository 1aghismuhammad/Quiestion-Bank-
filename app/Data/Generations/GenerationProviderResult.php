<?php

declare(strict_types=1);

namespace App\Data\Generations;

final readonly class GenerationProviderResult
{
    /**
     * @param  list<McqQuestionCandidate|TrueFalseQuestionCandidate|EssayQuestionCandidate>  $candidates
     */
    public function __construct(
        public array $candidates,
        public ProviderAttemptMetadata $metadata,
    ) {}
}
