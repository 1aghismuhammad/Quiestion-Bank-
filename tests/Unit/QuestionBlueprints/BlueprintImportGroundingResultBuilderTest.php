<?php

declare(strict_types=1);

namespace Tests\Unit\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportGroundingProviderResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\QuestionBlueprints\BlueprintMalformedResponseException;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Services\QuestionBlueprints\BlueprintImportGroundingResultBuilder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class BlueprintImportGroundingResultBuilderTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    private BlueprintImportGroundingResultBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->builder = $this->app->make(BlueprintImportGroundingResultBuilder::class);
    }

    public function test_sparse_provider_candidates_restore_order_build_server_evidence_and_rollups(): void
    {
        [$profile, $element] = $this->profileAndElement();
        $second = MaterialProfileElement::factory()->extracted()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $element->source_chunk_id,
            'text' => 'Bukti kedua',
            'char_start' => 13,
            'char_end' => 25,
            'evidence_excerpt' => 'kutipan',
            'evidence_locator' => 'halaman 1',
            'sort_order' => 1,
        ]);
        $interpretation = [
            'candidates' => [
                $this->candidate(['objective' => 'Objektif', 'topic' => 'Topik']),
                $this->candidate(['objective' => null]),
                $this->candidate(['objective' => 'Objektif lain']),
            ],
        ];
        $provider = $this->provider([
            [
                'index' => 0,
                'fields' => [
                    'objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]],
                    'topic' => ['status' => 'ambiguous', 'profile_element_ids' => [$element->profile_element_id, $second->profile_element_id]],
                ],
            ],
            [
                'index' => 2,
                'fields' => [
                    'objective' => ['status' => 'unresolved', 'profile_element_ids' => []],
                ],
            ],
        ], ['provider warning']);

        $result = $this->builder->build(
            $provider,
            $interpretation,
            [
                $element->profile_element_id => $element,
                $second->profile_element_id => $second,
            ],
            $profile->profile_version_id,
            'external-raw-byte-hash',
            $this->fingerprint(),
            ['prompt_version' => 'v1'],
        )->toArray();

        $this->assertSame([0, 1, 2], array_column($result['candidates'], 'index'));
        $this->assertSame('external-raw-byte-hash', $result['interpretation_result_sha256']);
        $this->assertSame('partial', $result['document_rollup']);
        $this->assertSame(['partial', 'not_applicable', 'ungrounded'], array_column($result['candidates'], 'rollup'));
        $this->assertSame('Objektif', $result['candidates'][0]['fields']['objective']['claim_raw']);
        $this->assertSame('not_applicable', $result['candidates'][0]['fields']['material']['status']);
        $this->assertSame($this->candidate(['objective' => 'Objektif', 'topic' => 'Topik'])['source_refs']['objective'], $result['candidates'][0]['import_provenance']['objective']);
        $this->assertSame([
            'profile_element_id' => $second->profile_element_id,
            'source_chunk_id' => $second->source_chunk_id,
            'char_start' => 13,
            'char_end' => 25,
            'evidence_excerpt' => 'kutipan',
            'evidence_locator' => 'halaman 1',
        ], $result['candidates'][0]['fields']['topic']['material_evidence'][1]);
        $this->assertSame(['provider warning'], $result['warnings']);
    }

    public function test_candidate_and_document_rollups_cover_grounded_partial_ungrounded_and_not_applicable(): void
    {
        [$profile, $element] = $this->profileAndElement();
        $interpretation = ['candidates' => [
            $this->candidate(['objective' => 'A']),
            $this->candidate(['objective' => 'B', 'topic' => 'T']),
            $this->candidate(['objective' => 'C']),
            $this->candidate(['objective' => null]),
        ]];
        $provider = $this->provider([
            ['index' => 0, 'fields' => ['objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]]]],
            ['index' => 1, 'fields' => [
                'objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]],
                'topic' => ['status' => 'unresolved', 'profile_element_ids' => []],
            ]],
            ['index' => 2, 'fields' => ['objective' => ['status' => 'unresolved', 'profile_element_ids' => []]]],
        ]);

        $result = $this->builder->build(
            $provider,
            $interpretation,
            [$element->profile_element_id => $element],
            $profile->profile_version_id,
            'hash',
            $this->fingerprint(),
            [],
        )->toArray();

        $this->assertSame(['grounded', 'partial', 'ungrounded', 'not_applicable'], array_column($result['candidates'], 'rollup'));
        $this->assertSame('partial', $result['document_rollup']);
    }

    public function test_document_rollup_follows_non_na_and_partial_rules(): void
    {
        [$profile, $element] = $this->profileAndElement();
        $cases = [
            [
                [['objective' => 'A', 'topic' => 'T']],
                [['index' => 0, 'fields' => [
                    'objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]],
                    'topic' => ['status' => 'unresolved', 'profile_element_ids' => []],
                ]]],
                'partial',
            ],
            [
                [['objective' => 'A', 'topic' => 'T'], ['objective' => null]],
                [
                    ['index' => 0, 'fields' => [
                        'objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]],
                        'topic' => ['status' => 'unresolved', 'profile_element_ids' => []],
                    ]],
                ],
                'partial',
            ],
            [
                [['objective' => 'A'], ['objective' => 'B']],
                [
                    ['index' => 0, 'fields' => ['objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]]]],
                    ['index' => 1, 'fields' => ['objective' => ['status' => 'unresolved', 'profile_element_ids' => []]]],
                ],
                'partial',
            ],
            [
                [['objective' => 'A'], ['objective' => null]],
                [['index' => 0, 'fields' => ['objective' => ['status' => 'unresolved', 'profile_element_ids' => []]]]],
                'ungrounded',
            ],
            [
                [['objective' => 'A'], ['objective' => null]],
                [['index' => 0, 'fields' => ['objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]]]]],
                'grounded',
            ],
        ];

        foreach ($cases as [$rawCandidates, $providerCandidates, $expected]) {
            $result = $this->builder->build(
                $this->provider($providerCandidates),
                ['candidates' => array_map(fn (array $raw): array => $this->candidate($raw), $rawCandidates)],
                [$element->profile_element_id => $element],
                $profile->profile_version_id,
                'hash',
                $this->fingerprint(),
                [],
            )->toArray();

            $this->assertSame($expected, $result['document_rollup']);
        }
    }

    public function test_empty_taxonomy_result_is_not_applicable_and_has_no_candidates(): void
    {
        $result = $this->builder->buildEmptyTaxonomyResult(
            17,
            'raw-hash',
            $this->fingerprint(),
            ['empty document'],
            ['prompt_version' => null],
        )->toArray();

        $this->assertSame([], $result['candidates']);
        $this->assertSame('not_applicable', $result['document_rollup']);
        $this->assertSame('raw-hash', $result['interpretation_result_sha256']);
        $this->assertNull($result['metadata']['prompt_version']);
    }

    public function test_field_status_cardinality_and_integer_invariants_are_strict(): void
    {
        [$profile, $element] = $this->profileAndElement();
        $elements = [$element->profile_element_id => $element];
        $invalidFields = [
            ['status' => 'grounded', 'profile_element_ids' => []],
            ['status' => 'unresolved', 'profile_element_ids' => [$element->profile_element_id]],
            ['status' => 'ambiguous', 'profile_element_ids' => [$element->profile_element_id]],
            ['status' => 'not_applicable', 'profile_element_ids' => []],
            ['status' => 'grounded', 'profile_element_ids' => [(string) $element->profile_element_id]],
            ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id, $element->profile_element_id]],
        ];

        foreach ($invalidFields as $field) {
            try {
                $this->buildOne($profile->profile_version_id, $elements, $field);
                $this->fail('Invalid field payload was accepted: '.json_encode($field));
            } catch (BlueprintMalformedResponseException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_more_than_four_references_rejects_the_whole_result(): void
    {
        [$profile, $first] = $this->profileAndElement();
        $elements = [$first->profile_element_id => $first];
        for ($i = 1; $i < 5; $i++) {
            $element = MaterialProfileElement::factory()->extracted()->create([
                'profile_version_id' => $profile->profile_version_id,
                'source_chunk_id' => $first->source_chunk_id,
                'sort_order' => $i,
            ]);
            $elements[$element->profile_element_id] = $element;
        }

        $this->expectException(BlueprintMalformedResponseException::class);
        $this->buildOne($profile->profile_version_id, $elements, [
            'status' => 'grounded',
            'profile_element_ids' => array_keys($elements),
        ]);
    }

    public function test_foreign_wrong_profile_unknown_and_suggested_references_are_rejected(): void
    {
        [$profile, $element] = $this->profileAndElement();
        [$otherProfile, $foreign] = $this->profileAndElement();
        $suggested = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'origin' => MaterialProfileElementOrigin::SUGGESTED,
        ]);

        foreach ([
            [$foreign->profile_element_id, [$element->profile_element_id => $element]],
            [999999, [$element->profile_element_id => $element]],
            [$suggested->profile_element_id, [$suggested->profile_element_id => $suggested]],
            [$foreign->profile_element_id, [$foreign->profile_element_id => $foreign]],
        ] as [$id, $elements]) {
            try {
                $this->buildOne($profile->profile_version_id, $elements, [
                    'status' => 'grounded',
                    'profile_element_ids' => [$id],
                ]);
                $this->fail('Foreign, wrong-profile, or suggested evidence was accepted.');
            } catch (BlueprintMalformedResponseException) {
                $this->assertTrue(true);
            }
        }

        $this->assertNotSame($profile->profile_version_id, $otherProfile->profile_version_id);
    }

    public function test_sparse_candidate_and_provider_dto_shapes_are_strict(): void
    {
        [$profile, $element] = $this->profileAndElement();
        $omitted = $this->builder->build(
            $this->provider([['index' => 0, 'fields' => []]]),
            ['candidates' => [$this->candidate()]],
            [$element->profile_element_id => $element],
            $profile->profile_version_id,
            'hash',
            $this->fingerprint(),
            [],
        );
        $this->assertSame('unresolved', $omitted->toArray()['candidates'][0]['fields']['objective']['status']);
        $this->assertSame([], $omitted->toArray()['candidates'][0]['fields']['objective']['material_evidence']);

        $cases = [
            [['index' => '0', 'fields' => []]],
            [['index' => 0, 'fields' => ['objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]]]], ['index' => 0, 'fields' => ['objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]]]]],
            [['index' => 1, 'fields' => ['objective' => ['status' => 'grounded', 'profile_element_ids' => [$element->profile_element_id]]]]],
        ];

        foreach ($cases as $candidates) {
            try {
                $this->builder->build(
                    $this->provider($candidates),
                    ['candidates' => [$this->candidate()]],
                    [$element->profile_element_id => $element],
                    $profile->profile_version_id,
                    'hash',
                    $this->fingerprint(),
                    [],
                );
                $this->fail('Malformed provider DTO payload was accepted.');
            } catch (BlueprintMalformedResponseException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_missing_indicator_with_unique_exact_match_is_grounded(): void
    {
        [$profile, $topic] = $this->profileAndElement();
        $indicator = $this->extracted($profile, $topic, MaterialProfileElementKind::INDICATOR, 'Indikator tepat');
        $fields = $this->fieldsFor($profile, [
            $topic->profile_element_id => $topic,
            $indicator->profile_element_id => $indicator,
        ], ['indicator' => 'Indikator tepat'], []);

        $this->assertSame('grounded', $fields['indicator']['status']);
        $this->assertSame([$indicator->profile_element_id], array_column($fields['indicator']['material_evidence'], 'profile_element_id'));
        $this->assertSame('Indikator tepat', $fields['indicator']['claim_raw']);
    }

    public function test_explicit_unresolved_with_unique_exact_match_is_rescued(): void
    {
        [$profile, $topic] = $this->profileAndElement();
        $indicator = $this->extracted($profile, $topic, MaterialProfileElementKind::INDICATOR, 'Indikator tepat');
        $fields = $this->fieldsFor($profile, [
            $topic->profile_element_id => $topic,
            $indicator->profile_element_id => $indicator,
        ], ['indicator' => 'Indikator tepat'], [
            'indicator' => ['status' => 'unresolved', 'profile_element_ids' => []],
        ]);

        $this->assertSame('grounded', $fields['indicator']['status']);
        $this->assertSame($indicator->profile_element_id, $fields['indicator']['material_evidence'][0]['profile_element_id']);
    }

    public function test_normalized_whitespace_and_case_still_exact_match(): void
    {
        [$profile, $topic] = $this->profileAndElement();
        $indicator = $this->extracted($profile, $topic, MaterialProfileElementKind::INDICATOR, '  NILAI   minimal kelulusan ujian tugas akhir adalah B  ');
        $fields = $this->fieldsFor($profile, [
            $indicator->profile_element_id => $indicator,
        ], ['indicator' => 'Nilai minimal kelulusan ujian tugas akhir adalah B'], []);

        $this->assertSame('grounded', $fields['indicator']['status']);
        $this->assertSame($indicator->profile_element_id, $fields['indicator']['material_evidence'][0]['profile_element_id']);
    }

    public function test_wrong_kind_and_non_extracted_text_do_not_rescue(): void
    {
        [$profile, $topic] = $this->profileAndElement();
        $objective = $this->extracted($profile, $topic, MaterialProfileElementKind::OBJECTIVE, 'Teks sama');
        $suggested = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $topic->source_chunk_id,
            'kind' => MaterialProfileElementKind::INDICATOR,
            'text' => 'Teks sama',
            'origin' => MaterialProfileElementOrigin::SUGGESTED,
        ]);
        $fields = $this->fieldsFor($profile, [
            $objective->profile_element_id => $objective,
            $suggested->profile_element_id => $suggested,
        ], ['indicator' => 'Teks sama'], []);

        $this->assertSame('unresolved', $fields['indicator']['status']);
        $this->assertSame([], $fields['indicator']['material_evidence']);
    }

    public function test_multiple_same_kind_exact_matches_stay_unresolved(): void
    {
        [$profile, $topic] = $this->profileAndElement();
        $first = $this->extracted($profile, $topic, MaterialProfileElementKind::INDICATOR, 'Indikator ganda');
        $second = $this->extracted($profile, $topic, MaterialProfileElementKind::INDICATOR, '  indikator   ganda  ');
        $fields = $this->fieldsFor($profile, [
            $first->profile_element_id => $first,
            $second->profile_element_id => $second,
        ], ['indicator' => 'Indikator ganda'], [
            'indicator' => ['status' => 'unresolved', 'profile_element_ids' => []],
        ]);

        $this->assertSame('unresolved', $fields['indicator']['status']);
        $this->assertSame([], $fields['indicator']['material_evidence']);
    }

    public function test_substring_and_material_cross_kind_do_not_rescue(): void
    {
        [$profile, $topic] = $this->profileAndElement();
        $broader = $this->extracted($profile, $topic, MaterialProfileElementKind::TOPIC, 'Ketentuan Publikasi Ilmiah');
        $materialAsTopic = $this->extracted($profile, $topic, MaterialProfileElementKind::TOPIC, 'Pedoman Penilaian Publikasi Ilmiah');
        $fields = $this->fieldsFor($profile, [
            $broader->profile_element_id => $broader,
            $materialAsTopic->profile_element_id => $materialAsTopic,
        ], [
            'topic' => 'Publikasi Ilmiah',
            'material' => 'Pedoman Penilaian Publikasi Ilmiah',
        ], []);

        $this->assertSame('unresolved', $fields['topic']['status']);
        $this->assertSame([], $fields['topic']['material_evidence']);
        $this->assertSame('unresolved', $fields['material']['status']);
        $this->assertSame([], $fields['material']['material_evidence']);
    }

    public function test_valid_provider_grounded_and_ambiguous_are_not_rewritten(): void
    {
        [$profile, $providerElement] = $this->profileAndElement();
        $exact = $this->extracted($profile, $providerElement, MaterialProfileElementKind::OBJECTIVE, 'Objektif server');
        $other = $this->extracted($profile, $providerElement, MaterialProfileElementKind::TOPIC, 'Topik lain');
        $second = $this->extracted($profile, $providerElement, MaterialProfileElementKind::TOPIC, 'Topik kedua');
        $fields = $this->fieldsFor($profile, [
            $providerElement->profile_element_id => $providerElement,
            $exact->profile_element_id => $exact,
            $other->profile_element_id => $other,
            $second->profile_element_id => $second,
        ], [
            'objective' => 'Objektif server',
            'topic' => 'Topik lain',
        ], [
            'objective' => ['status' => 'grounded', 'profile_element_ids' => [$providerElement->profile_element_id]],
            'topic' => ['status' => 'ambiguous', 'profile_element_ids' => [$other->profile_element_id, $second->profile_element_id]],
        ]);

        $this->assertSame('grounded', $fields['objective']['status']);
        $this->assertSame([$providerElement->profile_element_id], array_column($fields['objective']['material_evidence'], 'profile_element_id'));
        $this->assertSame('ambiguous', $fields['topic']['status']);
        $this->assertSame(
            [$other->profile_element_id, $second->profile_element_id],
            array_column($fields['topic']['material_evidence'], 'profile_element_id'),
        );
    }

    public function test_malformed_present_field_is_not_rescued(): void
    {
        [$profile, $topic] = $this->profileAndElement();
        $indicator = $this->extracted($profile, $topic, MaterialProfileElementKind::INDICATOR, 'Indikator tepat');

        try {
            $this->fieldsFor($profile, [
                $indicator->profile_element_id => $indicator,
            ], ['indicator' => 'Indikator tepat'], [
                'indicator' => ['status' => 'grounded', 'profile_element_ids' => []],
            ]);
            $this->fail('A malformed present field must still fail closed.');
        } catch (BlueprintMalformedResponseException $exception) {
            $this->assertSame('Grounded fields require 1..4 profile_element_ids.', $exception->getMessage());
        }
    }

    /**
     * @param  array<int, MaterialProfileElement>  $elements
     * @param  array<string, mixed>  $field
     */
    private function buildOne(int $profileId, array $elements, array $field): void
    {
        $this->builder->build(
            $this->provider([['index' => 0, 'fields' => ['objective' => $field]]]),
            ['candidates' => [$this->candidate()]],
            $elements,
            $profileId,
            'hash',
            $this->fingerprint(),
            [],
        );
    }

    /**
     * @param  array<int, MaterialProfileElement>  $elements
     * @param  array<string, string>  $claims
     * @param  array<string, array<string, mixed>>  $providerFields
     * @return array<string, array<string, mixed>>
     */
    private function fieldsFor($profile, array $elements, array $claims, array $providerFields): array
    {
        return $this->builder->build(
            $this->provider([['index' => 0, 'fields' => $providerFields]]),
            ['candidates' => [$this->candidate($claims)]],
            $elements,
            $profile->profile_version_id,
            'hash',
            $this->fingerprint(),
            [],
        )->toArray()['candidates'][0]['fields'];
    }

    private function extracted($profile, MaterialProfileElement $sibling, MaterialProfileElementKind $kind, string $text): MaterialProfileElement
    {
        return MaterialProfileElement::factory()->extracted()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $sibling->source_chunk_id,
            'kind' => $kind,
            'text' => $text,
            'evidence_excerpt' => 'excerpt-must-not-be-the-match-key',
            'char_start' => 1,
            'char_end' => 4,
        ]);
    }

    private function profileAndElement(): array
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['content' => 'Materi fotosintesis untuk pengujian.']);
        $profile = $this->readyProfile($owner, $material);

        return [$profile, MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->firstOrFail()];
    }

    private function provider(array $candidates, array $warnings = []): BlueprintImportGroundingProviderResult
    {
        return new BlueprintImportGroundingProviderResult(
            $candidates,
            $warnings,
            new BlueprintProviderAttemptMetadata('fake', 'model', 'v1'),
        );
    }

    private function fingerprint(): array
    {
        return [
            'material_content_hash' => str_repeat('a', 64),
            'material_file_hash' => null,
            'extractor_implementation' => 'test',
        ];
    }

    private function candidate(array $rawOverrides = [], array $refOverrides = []): array
    {
        $keys = ['objective', 'topic', 'material', 'indicator', 'cognitive_level', 'difficulty', 'question_type', 'assessment_type', 'numbering', 'extra'];
        $raw = [];
        $refs = [];
        foreach ($keys as $key) {
            $raw['raw_'.$key] = array_key_exists($key, $rawOverrides)
                ? $rawOverrides[$key]
                : ($key === 'objective' ? 'Obj' : null);
            $refs[$key] = $refOverrides[$key] ?? ($key === 'objective'
                ? [['kind' => 'paragraph', 'block_ordinal' => 0]]
                : []);
        }

        return [
            ...$raw,
            'source_refs' => $refs,
            'warnings' => [],
            'unresolved' => [],
            'cognitive_level' => null,
            'difficulty' => null,
            'question_type' => null,
            'assessment_type' => null,
            'requested_count' => null,
        ];
    }
}
