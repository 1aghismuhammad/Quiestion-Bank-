<?php

declare(strict_types=1);

namespace Tests\Unit\QuestionBlueprints;

use App\Actions\QuestionBlueprints\AssertImportGroundingEligibility;
use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Enums\MaterialProfileStatus;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprintImport;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class AssertImportGroundingEligibilityTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    private AssertImportGroundingEligibility $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->action = $this->app->make(AssertImportGroundingEligibility::class);
    }

    public function test_eligible_import_uses_exact_pinned_historical_ready_profile_and_raw_json_hash(): void
    {
        [$import, $profile] = $this->eligibleImport();
        $newer = MaterialProfileVersion::factory()->forOwner($import->user, $import->material)->create([
            'version' => 2,
            'status' => MaterialProfileStatus::READY,
            'material_content_hash' => $profile->material_content_hash,
            'material_file_hash' => $profile->material_file_hash,
            'extractor_implementation' => $profile->extractor_implementation,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $this->assertNotSame($profile->profile_version_id, $newer->profile_version_id);

        $result = $this->action->handle($import->fresh(), $import->user);

        $this->assertSame($profile->profile_version_id, $result['profile']->profile_version_id);
        $this->assertSame(
            hash('sha256', (string) $import->fresh()->getRawOriginal('interpretation_result')),
            $result['interpretationSha256'],
        );
        $this->assertSame(BlueprintImportInterpretationResult::SCHEMA_VERSION, $result['interpretationResult']['schema_version']);
    }

    public function test_import_must_be_extracted_interpretation_review_ready_and_well_formed(): void
    {
        $cases = [
            ['status', BlueprintImportStatus::PENDING, AssertImportGroundingEligibility::ERROR_IMPORT_NOT_EXTRACTED],
            ['interpretation_status', BlueprintImportInterpretationStatus::FAILED, AssertImportGroundingEligibility::ERROR_INTERPRETATION_NOT_READY],
            ['interpretation_result', ['schema_version' => 'wrong'], AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID],
        ];

        foreach ($cases as [$column, $value, $message]) {
            [$import] = $this->eligibleImport();
            $import->update([$column => $value]);
            try {
                $this->action->handle($import->fresh());
                $this->fail("Invalid {$column} was accepted.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_interpretation_requires_all_semantic_keys_null_canonicals_and_at_least_one_source_ref(): void
    {
        foreach ([
            fn (array $candidate): array => array_diff_key($candidate, ['raw_extra' => true]),
            function (array $candidate): array {
                $candidate['cognitive_level'] = 'C2';

                return $candidate;
            },
            function (array $candidate): array {
                foreach ($candidate['source_refs'] as $key => $_) {
                    $candidate['source_refs'][$key] = [];
                }

                return $candidate;
            },
        ] as $mutate) {
            [$import] = $this->eligibleImport();
            $interpretation = $import->interpretation_result;
            $interpretation['candidates'][0] = $mutate($interpretation['candidates'][0]);
            $import->update(['interpretation_result' => $interpretation]);

            try {
                $this->action->handle($import->fresh());
                $this->fail('Malformed interpretation candidate was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID, $exception->getMessage());
            }
        }
    }

    public function test_profile_must_exist_be_ready_and_match_import_ownership(): void
    {
        [$import, $profile] = $this->eligibleImport();
        $profile->update(['status' => MaterialProfileStatus::PROCESSING]);
        $this->assertInvalid($import, AssertImportGroundingEligibility::ERROR_PROFILE_NOT_READY);

        [$mismatched] = $this->eligibleImport();
        $otherOwner = $this->createCompleteUser();
        $otherMaterial = Material::factory()->text()->for($otherOwner)->create();
        $otherProfile = $this->readyProfile($otherOwner, $otherMaterial);
        $mismatched->update(['profile_version_id' => $otherProfile->profile_version_id]);
        $this->assertInvalid($mismatched, AssertImportGroundingEligibility::ERROR_PROFILE_OWNERSHIP);
    }

    public function test_actor_must_own_both_import_and_material(): void
    {
        [$import] = $this->eligibleImport();

        $this->expectException(AuthorizationException::class);
        $this->action->handle($import, $this->createCompleteUser());
    }

    public function test_import_profile_and_live_material_fingerprints_must_all_match(): void
    {
        foreach (['material_content_hash', 'material_file_hash', 'extractor_implementation'] as $column) {
            [$import] = $this->eligibleImport();
            $import->update([$column => $column === 'material_file_hash' ? 'different-file' : 'different']);
            $this->assertInvalid($import, AssertImportGroundingEligibility::ERROR_FINGERPRINT_MISMATCH);
        }

        [$profileImport, $profile] = $this->eligibleImport();
        $profile->update(['material_content_hash' => 'different-profile-hash']);
        $this->assertInvalid($profileImport, AssertImportGroundingEligibility::ERROR_FINGERPRINT_MISMATCH);

        [$liveImport] = $this->eligibleImport();
        $liveImport->material->update(['content' => 'Material changed after extraction']);
        $this->assertInvalid($liveImport, AssertImportGroundingEligibility::ERROR_FINGERPRINT_MISMATCH);
    }

    public function test_source_refs_are_strictly_validated_like_owner_review(): void
    {
        $cases = [
            [['kind' => 'paragraph', 'block_ordinal' => -1]],
            [['kind' => 'paragraph', 'block_ordinal' => '0']],
            [['kind' => 'paragraph', 'block_ordinal' => 0, 'role' => 'cell']],
            [['kind' => 'paragraph', 'block_ordinal' => 0, 'role' => 'weird']],
            [['kind' => 'cell', 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0]],
            [['kind' => 'cell', 'block_ordinal' => '0', 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0]],
            [['kind' => 'cell', 'block_ordinal' => -1, 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0]],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0, 'role' => 'paragraph']],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0, 'role' => 'unknown']],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0, 'paragraph_indexes' => '0']],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0, 'paragraph_indexes' => [0, 0]]],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0, 'paragraph_indexes' => [1, 0]]],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0, 'paragraph_indexes' => ['0']]],
            [['kind' => 'mystery', 'block_ordinal' => 0]],
            [['kind' => 'paragraph', 'block_ordinal' => 0, 'paragraph_indexes' => [0]]],
            [['kind' => 'paragraph', 'block_ordinal' => 0, 'table_index' => 0]],
            [['kind' => 'paragraph', 'block_ordinal' => 0, 'row_index' => 0]],
            [['kind' => 'paragraph', 'block_ordinal' => 0, 'cell_index' => 0]],
            [['kind' => 'paragraph', 'block_ordinal' => 0, 'unknown_key' => true]],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 0, 'cell_index' => 0, 'unknown_key' => true]],
        ];

        foreach ($cases as $refs) {
            [$import] = $this->eligibleImport();
            $interpretation = $import->interpretation_result;
            $interpretation['candidates'][0] = $this->candidate(refOverrides: ['objective' => $refs]);
            $import->update(['interpretation_result' => $interpretation]);
            $this->assertInvalid($import, AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID);
        }
    }

    public function test_canonical_paragraph_and_cell_source_refs_are_accepted(): void
    {
        foreach ([
            [['kind' => 'paragraph', 'block_ordinal' => 0]],
            [['kind' => 'paragraph', 'block_ordinal' => 0, 'role' => 'paragraph']],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 1, 'cell_index' => 2]],
            [['kind' => 'cell', 'block_ordinal' => 0, 'table_index' => 0, 'row_index' => 1, 'cell_index' => 2, 'paragraph_indexes' => [0, 2], 'role' => 'intersection']],
        ] as $refs) {
            [$import] = $this->eligibleImport();
            $interpretation = $import->interpretation_result;
            $interpretation['candidates'][0] = $this->candidate(refOverrides: ['objective' => $refs]);
            $import->update(['interpretation_result' => $interpretation]);

            $result = $this->action->handle($import->fresh());
            $this->assertSame($import->import_id, $result['import']->import_id);
        }
    }

    public function test_non_string_candidate_warnings_and_unresolved_are_rejected(): void
    {
        foreach ([
            function (array $candidate): array {
                $candidate['warnings'] = [1];

                return $candidate;
            },
            function (array $candidate): array {
                $candidate['unresolved'] = [null];

                return $candidate;
            },
        ] as $mutate) {
            [$import] = $this->eligibleImport();
            $interpretation = $import->interpretation_result;
            $interpretation['candidates'][0] = $mutate($interpretation['candidates'][0]);
            $import->update(['interpretation_result' => $interpretation]);
            $this->assertInvalid($import, AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID);
        }
    }

    public function test_unknown_unresolved_marker_is_rejected(): void
    {
        [$import] = $this->eligibleImport();
        $interpretation = $import->interpretation_result;
        $interpretation['candidates'][0]['unresolved'] = ['unknown_field'];
        $import->update(['interpretation_result' => $interpretation]);

        $this->assertInvalid($import, AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID);
    }

    public function test_eligibility_reads_fresh_persisted_material_not_stale_relation(): void
    {
        [$import] = $this->eligibleImport();
        $stale = $import->fresh();
        $stale->load('material');
        $stale->material->setAttribute('content', $stale->material->content);
        Material::query()->whereKey($stale->material_id)->update(['content' => 'Drifted live material content']);

        $this->assertInvalid($stale, AssertImportGroundingEligibility::ERROR_FINGERPRINT_MISMATCH);
    }

    private function assertInvalid(QuestionBlueprintImport $import, string $message): void
    {
        try {
            $this->action->handle($import->fresh());
            $this->fail("Expected {$message}.");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @return array{QuestionBlueprintImport, MaterialProfileVersion}
     */
    private function eligibleImport(): array
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['content' => 'Materi daur air']);
        $profile = $this->readyProfile($owner, $material);
        $import = QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
            'interpretation_result' => [
                'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
                'document_kind' => 'blueprint_like',
                'warnings' => [],
                'candidates' => [$this->candidate()],
            ],
            'material_content_hash' => $profile->material_content_hash,
            'material_file_hash' => $profile->material_file_hash,
            'extractor_implementation' => $profile->extractor_implementation,
        ]);

        return [$import, $profile];
    }

    private function candidate(array $rawOverrides = [], array $refOverrides = []): array
    {
        $keys = ['objective', 'topic', 'material', 'indicator', 'cognitive_level', 'difficulty', 'question_type', 'assessment_type', 'numbering', 'extra'];
        $raw = [];
        $refs = [];
        foreach ($keys as $key) {
            $raw['raw_'.$key] = $rawOverrides[$key] ?? ($key === 'objective' ? 'Obj' : null);
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
