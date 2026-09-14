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

    public function versionFor(QuestionType $type): string
    {
        return match ($type) {
            QuestionType::MULTIPLE_CHOICE => $this->mcq->version(),
            QuestionType::TRUE_FALSE => $this->trueFalse->version(),
            QuestionType::ESSAY => $this->essay->version(),
        };
    }

    public function assertSupported(QuestionType $type, string $version): void
    {
        $expected = $this->versionFor($type);

        if ($version !== $expected) {
            throw new GenerationConfigurationException('The generation prompt version is not supported.');
        }
    }
}
