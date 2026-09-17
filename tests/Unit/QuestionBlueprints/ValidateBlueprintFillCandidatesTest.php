<?php

declare(strict_types=1);

namespace Tests\Unit\QuestionBlueprints;

use App\Actions\QuestionBlueprints\AssertBlueprintShape;
use App\Actions\QuestionBlueprints\ValidateBlueprintFillCandidates;
use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillContextCatalogEntry;
use App\Enums\BlueprintMode;
use App\Exceptions\QuestionBlueprints\BlueprintCandidateValidationException;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Services\AI\BlueprintFillPromptBuilder;
use Tests\TestCase;

class ValidateBlueprintFillCandidatesTest extends TestCase
{
    private ValidateBlueprintFillCandidates $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = $this->app->make(ValidateBlueprintFillCandidates::class);
    }

    private function candidate(array $overrides = []): BlueprintFillCandidate
    {
        return new BlueprintFillCandidate(
            $overrides['objective'] ?? 'Tujuan',
            $overrides['topic'] ?? 'Topik',
            $overrides['indicator'] ?? 'Indikator',
            $overrides['cognitive_level'] ?? 'understand',
            $overrides['difficulty'] ?? 'medium',
            $overrides['requested_count'] ?? 1,
            $overrides['contexts'] ?? [],
            $overrides['question_type'] ?? null,
        );
    }

    private function getModels(): array
    {
        $material = new Material();
        $material->content = 'This is a unique test excerpt with repeating Repeat Repeat words.';

        $profile = new MaterialProfileVersion();
        $profile->profile_version_id = 1;

        $element = new MaterialProfileElement();
        $element->profile_version_id = 1;
        $element->source_chunk_id = 1;
        $element->profile_element_id = 1;
        $element->char_start = 0;
        $element->char_end = mb_strlen((string) $material->content, 'UTF-8');

        $chunk = new MaterialProfileChunk();
        $chunk->profile_version_id = 1;
        $chunk->profile_chunk_id = 1;
        $chunk->char_start = 0;
        $chunk->char_end = mb_strlen((string) $material->content, 'UTF-8');

        $catalog = [
            'ctx_1' => new BlueprintFillContextCatalogEntry(
                'ctx_1',
                'element',
                (string) $material->content,
                0,
                mb_strlen((string) $material->content, 'UTF-8'),
                $element,
                $chunk,
            )
        ];

        return [$material, $profile, $catalog];
    }

    public function test_v4_missing_evidence_text_throws(): void
    {
        [$material, $profile, $catalog] = $this->getModels();
        $candidate = $this->candidate([
            'contexts' => [
                ['context_ref' => 'ctx_1'] // missing evidence_text
            ]
        ]);

        try {
            $this->validator->handle([$candidate], $material, $profile, $catalog, BlueprintMode::Simple, null, null, BlueprintFillPromptBuilder::V4);
            $this->fail('Should throw exception for missing evidence text.');
        } catch (BlueprintCandidateValidationException $e) {
            $this->assertSame(BlueprintCandidateValidationException::REASON_EVIDENCE_NOT_FOUND, $e->internalReason);
        }
    }

    public function test_v4_ungrounded_evidence_text_throws(): void
    {
        [$material, $profile, $catalog] = $this->getModels();
        $candidate = $this->candidate([
            'contexts' => [
                ['context_ref' => 'ctx_1', 'evidence_text' => 'not in excerpt']
            ]
        ]);

        try {
            $this->validator->handle([$candidate], $material, $profile, $catalog, BlueprintMode::Simple, null, null, BlueprintFillPromptBuilder::V4);
            $this->fail('Should throw exception for ungrounded evidence text.');
        } catch (BlueprintCandidateValidationException $e) {
            $this->assertSame(BlueprintCandidateValidationException::REASON_EVIDENCE_NOT_FOUND, $e->internalReason);
        }
    }

    public function test_v4_ambiguous_evidence_text_throws(): void
    {
        [$material, $profile, $catalog] = $this->getModels();
        $candidate = $this->candidate([
            'contexts' => [
                ['context_ref' => 'ctx_1', 'evidence_text' => 'Repeat']
            ]
        ]);

        try {
            $this->validator->handle([$candidate], $material, $profile, $catalog, BlueprintMode::Simple, null, null, BlueprintFillPromptBuilder::V4);
            $this->fail('Should throw exception for ambiguous evidence text.');
        } catch (BlueprintCandidateValidationException $e) {
            $this->assertSame(BlueprintCandidateValidationException::REASON_EVIDENCE_AMBIGUOUS, $e->internalReason);
        }
    }

    public function test_v4_valid_evidence_text_passes(): void
    {
        [$material, $profile, $catalog] = $this->getModels();
        $candidate = $this->candidate([
            'contexts' => [
                ['context_ref' => 'ctx_1', 'evidence_text' => 'unique test excerpt']
            ]
        ]);

        $this->validator->handle([$candidate], $material, $profile, $catalog, BlueprintMode::Simple, null, null, BlueprintFillPromptBuilder::V4);
        $this->assertTrue(true); // Should not throw
    }
}
