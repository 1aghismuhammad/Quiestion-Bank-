<?php

declare(strict_types=1);

namespace Tests\Unit\Generations;

use App\Actions\Generations\ValidateEssayCandidateSet;
use App\Actions\Generations\ValidateTrueFalseCandidateSet;
use App\Data\Generations\EssayQuestionCandidate;
use App\Data\Generations\TrueFalseQuestionCandidate;
use App\Support\Generations\TrueFalseDistribution;
use Tests\TestCase;

class TypedGenerationValidationTest extends TestCase
{
    public function test_true_false_rejects_string_answers_and_compound_statements(): void
    {
        $validator = $this->app->make(ValidateTrueFalseCandidateSet::class);
        $result = $validator->handle([
            new TrueFalseQuestionCandidate('Fotosintesis membutuhkan cahaya.', 'true', 'Karena materi.'),
            new TrueFalseQuestionCandidate('A. B?', true, 'Karena materi.'),
            new TrueFalseQuestionCandidate('Tumbuhan membuat makanan. Hewan tidak.', false, 'Karena materi.'),
            new TrueFalseQuestionCandidate('Fotosintesis membutuhkan cahaya matahari.', true, 'Karena klorofil menyerap cahaya.'),
        ], [], [], 2);

        $this->assertCount(1, $result['valid']);
        $this->assertTrue($result['valid'][0]->correctAnswer);
        $this->assertContains('invalid_candidate', $result['invalidReasons']);
    }

    public function test_true_false_repair_keeps_remaining_distribution(): void
    {
        $this->assertSame(
            ['true' => 0, 'false' => 2],
            TrueFalseDistribution::remaining(4, 2, 0),
        );
        $this->assertTrue(TrueFalseDistribution::canAccept(4, 2, 0, false));
        $this->assertFalse(TrueFalseDistribution::canAccept(4, 2, 0, true));
        $this->assertTrue(TrueFalseDistribution::isBalanced(4, 2, 2));
        $this->assertFalse(TrueFalseDistribution::isBalanced(4, 3, 1));
        $this->assertTrue(TrueFalseDistribution::isFeasible(4, 2, 0));
        $this->assertFalse(TrueFalseDistribution::isFeasible(4, 3, 0));
        $this->assertTrue(TrueFalseDistribution::isFeasible(4, 2, 2));
        $this->assertFalse(TrueFalseDistribution::isFeasible(4, 3, 1));
    }

    public function test_essay_requires_model_answer_and_rubric_and_rejects_empty_fields(): void
    {
        $validator = $this->app->make(ValidateEssayCandidateSet::class);
        $result = $validator->handle([
            new EssayQuestionCandidate('Jelaskan fotosintesis.', 'Tumbuhan membuat makanan.', 'Unsur wajib; penuh; parsial; kurang.', 'Karena materi.'),
            new EssayQuestionCandidate('Jelaskan respirasi.', '', 'Rubrik', 'Penjelasan'),
            new EssayQuestionCandidate('Jelaskan transpirasi.', 'Jawaban', '', 'Penjelasan'),
        ], []);

        $this->assertCount(1, $result['valid']);
        $this->assertSame('Jelaskan fotosintesis.', $result['valid'][0]->question);
        $this->assertNotSame('', $result['valid'][0]->modelAnswer);
        $this->assertNotSame('', $result['valid'][0]->rubric);
    }
}
