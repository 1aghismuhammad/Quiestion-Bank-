<?php

declare(strict_types=1);

namespace Tests\Unit\Generations;

use App\Data\Generations\GenerationProviderRequest;
use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Exceptions\Generations\GenerationConfigurationException;
use App\Services\AI\EssayPromptBuilder;
use App\Services\AI\TrueFalsePromptBuilder;
use Tests\TestCase;

class TypedPromptBuilderTest extends TestCase
{
    public function test_true_false_v1_forbids_string_answers_and_options(): void
    {
        $builder = new TrueFalsePromptBuilder;
        $system = $builder->systemInstruction(OutputLanguage::ID, TrueFalsePromptBuilder::V1);
        $user = $builder->userPrompt($this->request(QuestionType::TRUE_FALSE, TrueFalsePromptBuilder::V1), TrueFalsePromptBuilder::V1);

        $this->assertSame(TrueFalsePromptBuilder::V1, $builder->version());
        $this->assertStringContainsString('JSON boolean true or false', $system);
        $this->assertStringContainsString('Do not generate options', $system);
        $this->assertStringContainsString('exactly one main proposition', $system);
        $this->assertStringContainsString('Question type: true_false', $user);
        $this->assertSame('boolean', $builder->responseSchema()['properties']['questions']['items']['properties']['correct_answer']['type']);
    }

    public function test_essay_v1_requires_model_answer_and_rubric(): void
    {
        $builder = new EssayPromptBuilder;
        $system = $builder->systemInstruction(OutputLanguage::ID, EssayPromptBuilder::V1);
        $schema = $builder->responseSchema();

        $this->assertSame(EssayPromptBuilder::V1, $builder->version());
        $this->assertStringContainsString('model_answer', $system);
        $this->assertStringContainsString('partial-credit', $system);
        $this->assertSame(
            ['question', 'model_answer', 'rubric', 'explanation'],
            $schema['properties']['questions']['items']['required'],
        );
    }

    public function test_unsupported_typed_identities_fail_closed(): void
    {
        config(['generation.true_false_prompt_version' => 'true-false-v9']);
        $this->expectException(GenerationConfigurationException::class);
        (new TrueFalsePromptBuilder)->version();
    }

    private function request(QuestionType $type, string $version): GenerationProviderRequest
    {
        return new GenerationProviderRequest(
            outputLanguage: OutputLanguage::ID,
            difficultyLevel: DifficultyLevel::MEDIUM,
            assessmentType: AssessmentType::FORMATIVE,
            requestedCount: 4,
            acceptedQuestionTexts: [],
            materialContent: 'Fotosintesis membutuhkan cahaya.',
            purpose: GenerationAttemptPurpose::INITIAL,
            model: 'gemini-3.5-flash-lite',
            questionType: $type,
            promptVersion: $version,
        );
    }
}
