<?php

declare(strict_types=1);

namespace Tests\Feature\MySqlConcurrency;

use App\Data\QuestionBlueprints\BlueprintImportGroundingResult;
use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Enums\BlueprintImportGroundingStatus;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintImport;
use App\Models\QuestionBlueprintRow;
use App\Models\QuestionBlueprintRowContext;
use App\Models\QuestionBlueprintSeries;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MySqlConcurrency\ConcurrentRunner;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;

#[Group('mysql-concurrency')]
class BlueprintImportDraftConversionConcurrencyTest extends MySqlConcurrencyTestCase
{
    use CreatesQuestionBlueprints;

    public function test_two_workers_convert_the_same_import_once(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Materi daur air. ', 8),
        ]);
        $profile = $this->readyProfile($owner, $material);
        $element = MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->firstOrFail();
        $import = QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
            'interpretation_result' => $this->interpretation(),
            'material_content_hash' => $profile->material_content_hash,
            'material_file_hash' => $profile->material_file_hash,
            'extractor_implementation' => $profile->extractor_implementation,
        ]);
        $import->refresh();
        $import->update([
            'grounding_status' => BlueprintImportGroundingStatus::READY,
            'grounding_result' => $this->grounding(
                $profile,
                $element,
                hash('sha256', (string) $import->getRawOriginal('interpretation_result')),
            ),
        ]);

        $outputs = ConcurrentRunner::run('convert-blueprint-import', [
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'import_id' => $import->import_id,
            'title' => 'Kisi impor',
            'assessment_type' => 'formative',
            'mode' => 'simple',
            'selected_indexes' => [0],
            'rows' => [[
                'index' => 0,
                'cognitive_level' => 'understand',
                'difficulty' => 'easy',
                'question_type' => 'essay',
                'requested_count' => 1,
            ]],
        ]);

        $decoded = [];

        foreach ($outputs as $output) {
            $this->assertSame(0, $output['exitCode'], $output['output'].$output['error']);
            $decoded[] = json_decode($output['output'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($decoded[array_key_last($decoded)]['success']);
        }

        $blueprintIds = array_column($decoded, 'blueprint_id');
        $this->assertCount(2, $blueprintIds);
        $this->assertSame($blueprintIds[0], $blueprintIds[1]);

        $import->refresh();
        $this->assertSame($blueprintIds[0], $import->created_blueprint_id);
        $this->assertSame(1, QuestionBlueprint::query()->count());
        $this->assertSame(1, QuestionBlueprintSeries::query()->count());
        $this->assertSame(1, QuestionBlueprintRow::query()->count());
        $this->assertSame(1, QuestionBlueprintRowContext::query()->count());
        $this->assertSame(
            $import->created_blueprint_id,
            QuestionBlueprint::query()->value('blueprint_id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function interpretation(): array
    {
        $keys = ['objective', 'topic', 'material', 'indicator', 'cognitive_level', 'difficulty', 'question_type', 'assessment_type', 'numbering', 'extra'];
        $candidate = [
            'source_refs' => [],
            'warnings' => [],
            'unresolved' => [],
            'cognitive_level' => null,
            'difficulty' => null,
            'question_type' => null,
            'assessment_type' => null,
            'requested_count' => null,
        ];

        foreach ($keys as $key) {
            $candidate['raw_'.$key] = match ($key) {
                'objective' => 'Tujuan daur air',
                'topic' => 'Topik daur air',
                'indicator' => 'Indikator daur air',
                default => null,
            };
            $candidate['source_refs'][$key] = $key === 'objective'
                ? [['kind' => 'paragraph', 'block_ordinal' => 0]]
                : [];
        }

        return [
            'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
            'document_kind' => 'blueprint_like',
            'warnings' => [],
            'candidates' => [$candidate],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grounding($profile, MaterialProfileElement $element, string $sha): array
    {
        $evidence = [[
            'profile_element_id' => $element->profile_element_id,
            'source_chunk_id' => $element->source_chunk_id,
            'char_start' => 0,
            'char_end' => 12,
            'evidence_excerpt' => 'BUKTI-JANGAN-DISALIN',
            'evidence_locator' => null,
        ]];
        $field = static fn (string $claim): array => [
            'claim_raw' => $claim,
            'status' => 'grounded',
            'material_evidence' => $evidence,
        ];

        return [
            'schema_version' => BlueprintImportGroundingResult::SCHEMA_VERSION,
            'grounded_profile_version_id' => $profile->profile_version_id,
            'interpretation_schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
            'interpretation_result_sha256' => $sha,
            'fingerprint' => [
                'material_content_hash' => $profile->material_content_hash,
                'material_file_hash' => $profile->material_file_hash,
                'extractor_implementation' => $profile->extractor_implementation,
            ],
            'document_rollup' => 'grounded',
            'warnings' => [],
            'metadata' => [],
            'candidates' => [[
                'index' => 0,
                'rollup' => 'grounded',
                'import_provenance' => [],
                'fields' => [
                    'objective' => $field('Tujuan daur air'),
                    'topic' => $field('Topik daur air'),
                    'indicator' => $field('Indikator daur air'),
                    'material' => [
                        'claim_raw' => null,
                        'status' => 'not_applicable',
                        'material_evidence' => [],
                    ],
                ],
            ]],
        ];
    }
}
