<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\QuestionType;
use App\Exceptions\Generations\GenerationConfigurationException;

class GenerationPromptIdentity
{
    public function __construct(
        private McqPromptBuilder $mcq,
        private TrueFalsePromptBuilder $trueFalse,
        private EssayPromptBuilder $essay,
    ) {}

    public function versionForLegacy(QuestionType $type): string
    {
        return match ($type) {
            QuestionType::MULTIPLE_CHOICE => $this->mcq->version(),
            QuestionType::TRUE_FALSE => $this->trueFalse->version(),
            QuestionType::ESSAY => $this->essay->version(),
        };
    }

    public function versionForBlueprintRun(QuestionType $type): string
    {
        return match ($type) {
            QuestionType::MULTIPLE_CHOICE => McqPromptBuilder::V4,
            QuestionType::TRUE_FALSE => TrueFalsePromptBuilder::V2,
            QuestionType::ESSAY => EssayPromptBuilder::V2,
        };
    }

    public function assertSupported(QuestionType $type, string $version): void
    {
        match ($type) {
            QuestionType::MULTIPLE_CHOICE => $this->mcq->assertSupported($version),
            QuestionType::TRUE_FALSE => $this->trueFalse->assertSupported($version),
            QuestionType::ESSAY => $this->essay->assertSupported($version),
        };
    }
}
