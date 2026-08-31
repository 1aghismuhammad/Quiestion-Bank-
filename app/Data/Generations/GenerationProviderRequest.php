<?php

declare(strict_types=1);

namespace App\Data\Generations;

use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\OutputLanguage;

final readonly class GenerationProviderRequest
{
    /**
     * @param  list<string>  $acceptedQuestionTexts
     */
    public function __construct(
        public OutputLanguage $outputLanguage,
        public DifficultyLevel $difficultyLevel,
        public AssessmentType $assessmentType,
        public int $requestedCount,
        public array $acceptedQuestionTexts,
        public string $materialContent,
        public GenerationAttemptPurpose $purpose,
        public string $model,
        public ?int $generationId = null,
    ) {}
}
