<?php

declare(strict_types=1);

namespace Tests\Unit\QuestionBlueprints;

use App\Actions\QuestionBlueprints\ResolveBlueprintImportOwnerReview;
use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Models\Material;
use App\Models\QuestionBlueprintImport;
use App\Services\QuestionBlueprints\BlueprintImportInterpretationResultBuilder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class ResolveBlueprintImportOwnerReviewTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    private ResolveBlueprintImportOwnerReview $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->resolver = app(ResolveBlueprintImportOwnerReview::class);
    }

    public function test_valid_v1_result_maps_raw_fields_and_one_based_provenance(): void
    {
        $import = $this->importWithResult($this->validResult());

        $view = $this->resolver->handle($import);

        $this->assertTrue($view->resultValid);
        $this->assertSame('blueprint_like', $view->documentKind);
        $this->assertSame('Kisi-kisi terdeteksi', $view->documentKindLabel);
        $this->assertCount(1, $view->candidates);

        $candidate = $view->candidates[0];
        $objective = collect($candidate['fields'])->firstWhere('key', 'objective');
        $this->assertSame('Menjelaskan fotosintesis', $objective['value']);
        $this->assertFalse($objective['empty']);

        $emptyTopic = collect($candidate['fields'])->firstWhere('key', 'topic');
        $this->assertSame('Belum teridentifikasi', $emptyTopic['value']);
        $this->assertTrue($emptyTopic['empty']);

        $locations = $candidate['provenance'][0]['locations'];
        $this->assertSame(['Tabel 1 · Baris 2 · Kolom 1'], $locations);

        $unresolvedKeys = array_column($candidate['unresolved'], 'key');
        $this->assertContains('topic', $unresolvedKeys);
        $this->assertSame('Topik', $candidate['unresolved'][0]['label']);
    }

    public function test_document_kind_labels(): void
    {
        foreach ([
            'blueprint_like' => 'Kisi-kisi terdeteksi',
            'matrix_incomplete' => 'Struktur kisi-kisi belum lengkap',
            'taxonomy_non_blueprint' => 'Dokumen taksonomi, bukan kisi-kisi',
            'ambiguous' => 'Struktur dokumen belum dapat dipastikan',
            'empty' => 'Tidak ada isi yang dapat ditinjau',
        ] as $kind => $label) {
            $import = $this->importWithResult([
                'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
                'document_kind' => $kind,
                'candidates' => [],
                'warnings' => [],
                'metadata' => [],
            ]);

            $view = $this->resolver->handle($import);
            $this->assertSame($label, $view->documentKindLabel, $kind);
            $this->assertTrue($view->resultValid, $kind);
        }
    }

    public function test_null_result_is_fail_closed(): void
    {
        $import = $this->importWithResult(null);
        $view = $this->resolver->handle($import);

        $this->assertFalse($view->resultValid);
        $this->assertNotNull($view->presentationError);
        $this->assertSame([], $view->candidates);
    }

    public function test_wrong_schema_is_fail_closed(): void
    {
        $import = $this->importWithResult([
            'schema_version' => 'other',
            'document_kind' => 'blueprint_like',
            'candidates' => [],
            'warnings' => [],
        ]);

        $this->assertFalse($this->resolver->handle($import)->resultValid);
    }

    public function test_unknown_kind_is_fail_closed(): void
    {
        $import = $this->importWithResult([
            'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
            'document_kind' => 'not_a_kind',
            'candidates' => [],
            'warnings' => [],
        ]);

        $this->assertFalse($this->resolver->handle($import)->resultValid);
    }

    public function test_malformed_candidates_are_fail_closed(): void
    {
        $import = $this->importWithResult([
            'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
            'document_kind' => 'blueprint_like',
            'candidates' => 'nope',
            'warnings' => [],
        ]);

        $this->assertFalse($this->resolver->handle($import)->resultValid);
    }

    public function test_malformed_source_refs_are_fail_closed(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs']['objective'] = ['bad'];

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_malformed_warnings_are_fail_closed(): void
    {
        $result = $this->validResult();
        $result['warnings'] = [1, 2];

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_malformed_unresolved_are_fail_closed(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['unresolved'] = ['not_a_semantic_key'];

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_can_retry_only_when_extracted_and_failed(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $profile = $this->readyProfile($owner, $material);

        $failed = QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
            'interpretation_queued_at' => now(),
        ]);

        $this->assertTrue($this->resolver->handle($failed)->canRetry);

        $queued = QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::QUEUED,
            'interpretation_queued_at' => now(),
        ]);

        $this->assertFalse($this->resolver->handle($queued)->canRetry);
    }

    public function test_paragraph_provenance_is_one_based(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs'] = $this->emptySourceRefs([
            'objective' => [[
                'kind' => 'paragraph',
                'block_ordinal' => 0,
                'role' => 'paragraph',
            ]],
        ]);

        $view = $this->resolver->handle($this->importWithResult($result));
        $locations = $view->candidates[0]['provenance'][0]['locations'];

        $this->assertSame(['Paragraf 1 (paragraf)'], $locations);
    }

    public function test_missing_raw_key_is_fail_closed(): void
    {
        $result = $this->validResult();
        unset($result['candidates'][0]['raw_topic']);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_source_refs_missing_is_fail_closed(): void
    {
        $result = $this->validResult();
        unset($result['candidates'][0]['source_refs']);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_semantic_source_refs_key_missing_is_fail_closed(): void
    {
        $result = $this->validResult();
        unset($result['candidates'][0]['source_refs']['topic']);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_warnings_missing_is_fail_closed(): void
    {
        $result = $this->validResult();
        unset($result['warnings']);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_candidate_with_zero_provenance_is_fail_closed(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs'] = $this->emptySourceRefs();

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_one_hundred_candidates_are_valid(): void
    {
        $result = $this->validResult();
        $result['candidates'] = [];

        for ($i = 0; $i < 100; $i++) {
            $candidate = $this->validCandidate([
                'raw_objective' => "Objective-{$i}",
                'source_refs' => $this->emptySourceRefs([
                    'objective' => [['kind' => 'paragraph', 'block_ordinal' => $i]],
                ]),
            ]);
            $result['candidates'][] = $candidate;
        }

        $view = $this->resolver->handle($this->importWithResult($result));

        $this->assertTrue($view->resultValid);
        $this->assertCount(100, $view->candidates);
    }

    public function test_one_hundred_one_candidates_are_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'] = [];

        for ($i = 0; $i < 101; $i++) {
            $result['candidates'][] = $this->validCandidate([
                'raw_objective' => "Objective-{$i}",
                'source_refs' => $this->emptySourceRefs([
                    'objective' => [['kind' => 'paragraph', 'block_ordinal' => $i]],
                ]),
            ]);
        }

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_taxonomy_non_blueprint_with_candidate_is_invalid(): void
    {
        $result = $this->validResult();
        $result['document_kind'] = 'taxonomy_non_blueprint';

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_empty_kind_with_candidate_is_invalid(): void
    {
        $result = $this->validResult();
        $result['document_kind'] = 'empty';

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_paragraph_negative_block_ordinal_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs'] = $this->emptySourceRefs([
            'objective' => [['kind' => 'paragraph', 'block_ordinal' => -1]],
        ]);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_paragraph_numeric_string_block_ordinal_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs'] = $this->emptySourceRefs([
            'objective' => [['kind' => 'paragraph', 'block_ordinal' => '0']],
        ]);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_paragraph_incompatible_role_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs'] = $this->emptySourceRefs([
            'objective' => [['kind' => 'paragraph', 'block_ordinal' => 0, 'role' => 'cell']],
        ]);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_paragraph_unknown_role_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs'] = $this->emptySourceRefs([
            'objective' => [['kind' => 'paragraph', 'block_ordinal' => 0, 'role' => 'header']],
        ]);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_cell_missing_block_ordinal_is_invalid(): void
    {
        $result = $this->validResult();
        $ref = $result['candidates'][0]['source_refs']['objective'][0];
        unset($ref['block_ordinal']);
        $result['candidates'][0]['source_refs'] = $this->emptySourceRefs([
            'objective' => [$ref],
        ]);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_cell_negative_coordinate_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs']['objective'][0]['row_index'] = -1;

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_cell_numeric_string_coordinate_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs']['objective'][0]['table_index'] = '0';

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_cell_unknown_role_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs']['objective'][0]['role'] = 'mystery';

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_cell_paragraph_role_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs']['objective'][0]['role'] = 'paragraph';

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_malformed_paragraph_indexes_are_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs']['objective'][0]['paragraph_indexes'] = 'nope';

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_duplicate_paragraph_indexes_are_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs']['objective'][0]['paragraph_indexes'] = [0, 0];

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_unsorted_paragraph_indexes_are_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['source_refs']['objective'][0]['paragraph_indexes'] = [2, 1];

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_canonical_field_missing_is_invalid(): void
    {
        $result = $this->validResult();
        unset($result['candidates'][0]['requested_count']);

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_canonical_field_non_null_is_invalid(): void
    {
        $result = $this->validResult();
        $result['candidates'][0]['question_type'] = 'mcq';

        $this->assertFalse($this->resolver->handle($this->importWithResult($result))->resultValid);
    }

    public function test_impossible_extraction_interpretation_combinations_are_fail_closed(): void
    {
        $cases = [
            [BlueprintImportStatus::PENDING, BlueprintImportInterpretationStatus::QUEUED],
            [BlueprintImportStatus::PROCESSING, BlueprintImportInterpretationStatus::REVIEW_READY],
            [BlueprintImportStatus::FAILED, BlueprintImportInterpretationStatus::QUEUED],
            [BlueprintImportStatus::FAILED, BlueprintImportInterpretationStatus::FAILED],
        ];

        foreach ($cases as [$extraction, $interpretation]) {
            $owner = $this->createCompleteUser();
            $material = Material::factory()->text()->for($owner)->create();
            $profile = $this->readyProfile($owner, $material);

            $import = QuestionBlueprintImport::factory()->create([
                'user_id' => $owner->id,
                'material_id' => $material->material_id,
                'profile_version_id' => $profile->profile_version_id,
                'status' => $extraction,
                'interpretation_status' => $interpretation,
                'interpretation_queued_at' => now(),
                'interpretation_result' => $this->validResult(),
            ]);

            $view = $this->resolver->handle($import);

            $this->assertFalse($view->resultValid, "{$extraction->value}+{$interpretation->value}");
            $this->assertTrue($view->terminal, "{$extraction->value}+{$interpretation->value}");
            $this->assertFalse($view->inFlight, "{$extraction->value}+{$interpretation->value}");
            $this->assertFalse($view->canRetry, "{$extraction->value}+{$interpretation->value}");
            $this->assertSame([], $view->candidates, "{$extraction->value}+{$interpretation->value}");
            $this->assertNotNull($view->presentationError, "{$extraction->value}+{$interpretation->value}");
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function importWithResult(?array $result): QuestionBlueprintImport
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $profile = $this->readyProfile($owner, $material);

        return QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'structured_document' => ['blocks' => []],
            'structure_schema_version' => 'blueprint-import-structure-v1',
            'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
            'interpretation_prompt_version' => 'blueprint-import-interpret-v1',
            'interpretation_queued_at' => now()->subMinute(),
            'interpretation_completed_at' => now(),
            'interpretation_result' => $result,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validResult(): array
    {
        return [
            'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
            'document_kind' => 'blueprint_like',
            'warnings' => ['Periksa baris header'],
            'metadata' => ['prompt_version' => 'blueprint-import-interpret-v1'],
            'candidates' => [$this->validCandidate()],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validCandidate(array $overrides = []): array
    {
        return array_merge([
            'raw_objective' => 'Menjelaskan fotosintesis',
            'raw_topic' => null,
            'raw_material' => '',
            'raw_indicator' => null,
            'raw_cognitive_level' => 'C2',
            'raw_difficulty' => null,
            'raw_question_type' => 'Pilihan Ganda',
            'raw_assessment_type' => null,
            'raw_numbering' => '1-5',
            'raw_extra' => null,
            'source_refs' => $this->emptySourceRefs([
                'objective' => [[
                    'kind' => 'cell',
                    'block_ordinal' => 1,
                    'table_index' => 0,
                    'row_index' => 1,
                    'cell_index' => 0,
                ]],
            ]),
            'warnings' => ['<script>alert(1)</script>'],
            'unresolved' => ['topic'],
            'cognitive_level' => null,
            'difficulty' => null,
            'question_type' => null,
            'assessment_type' => null,
            'requested_count' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $filled
     * @return array<string, list<array<string, mixed>>>
     */
    private function emptySourceRefs(array $filled = []): array
    {
        $refs = [];

        foreach (BlueprintImportInterpretationResultBuilder::SEMANTIC_KEYS as $key) {
            $refs[$key] = $filled[$key] ?? [];
        }

        return $refs;
    }
}
