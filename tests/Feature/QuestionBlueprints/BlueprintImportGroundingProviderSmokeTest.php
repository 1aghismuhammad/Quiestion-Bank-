<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Contracts\AI\QuestionBlueprintImportGroundingProvider;
use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintImport;
use App\Models\QuestionBlueprintRow;
use App\Models\QuestionSet;
use App\Services\AI\BlueprintImportGroundingPromptBuilder;
use App\Services\QuestionBlueprints\BlueprintImportGroundingCatalogBuilder;
use App\Services\QuestionBlueprints\BlueprintImportGroundingResultBuilder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

/**
 * Opt-in live Gemini smoke for the full K2C grounding contract.
 *
 * Set BLUEPRINT_IMPORT_GROUNDING_PROVIDER_SMOKE=1 and GEMINI_API_KEY to run it.
 * The default test suite skips before any HTTP request can be made.
 */
class BlueprintImportGroundingProviderSmokeTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    private const OBJECTIVE = 'Peserta didik menjelaskan proses evaporasi.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_live_provider_grounds_through_server_authoritative_evidence(): void
    {
        if (env('BLUEPRINT_IMPORT_GROUNDING_PROVIDER_SMOKE') !== '1') {
            Http::preventStrayRequests();
            $this->markTestSkipped('Live grounding provider smoke test is opt-in.');
        }

        $this->assertNotEmpty(config('question_blueprint.api_key'), 'GEMINI_API_KEY is required for the opt-in smoke test.');

        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => self::OBJECTIVE,
        ]);
        $profile = $this->readyProfile($owner, $material);
        $element = MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->firstOrFail();
        $element->update([
            'kind' => MaterialProfileElementKind::OBJECTIVE,
            'text' => self::OBJECTIVE,
            'evidence_excerpt' => self::OBJECTIVE,
            'evidence_locator' => $element->evidence_locator ?? 'paragraf 1',
            'char_start' => 0,
            'char_end' => mb_strlen(self::OBJECTIVE, 'UTF-8'),
        ]);
        $element->refresh();

        $interpretation = [
            'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
            'document_kind' => 'blueprint_like',
            'warnings' => [],
            'candidates' => [[
                'raw_objective' => self::OBJECTIVE,
                'raw_topic' => null,
                'raw_material' => null,
                'raw_indicator' => null,
                'raw_cognitive_level' => null,
                'raw_difficulty' => null,
                'raw_question_type' => null,
                'raw_assessment_type' => null,
                'raw_numbering' => null,
                'raw_extra' => null,
                'source_refs' => [
                    'objective' => [['kind' => 'paragraph', 'block_ordinal' => 0]],
                    'topic' => [],
                    'material' => [],
                    'indicator' => [],
                    'cognitive_level' => [],
                    'difficulty' => [],
                    'question_type' => [],
                    'assessment_type' => [],
                    'numbering' => [],
                    'extra' => [],
                ],
                'warnings' => [],
                'unresolved' => [],
                'cognitive_level' => null,
                'difficulty' => null,
                'question_type' => null,
                'assessment_type' => null,
                'requested_count' => null,
            ]],
        ];

        $import = QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
            'interpretation_result' => $interpretation,
            'material_content_hash' => $profile->material_content_hash,
            'material_file_hash' => $profile->material_file_hash,
            'extractor_implementation' => $profile->extractor_implementation,
        ]);

        $catalogBuilder = $this->app->make(BlueprintImportGroundingCatalogBuilder::class);
        $catalog = $catalogBuilder->build($profile);
        $serialized = $catalogBuilder->serializeRequest([
            ['index' => 0, 'claims' => ['objective' => self::OBJECTIVE]],
        ], $catalog['catalog']);

        $providerResult = $this->app->make(QuestionBlueprintImportGroundingProvider::class)->ground(
            $serialized['json'],
            BlueprintImportGroundingPromptBuilder::V1,
            (string) config('question_blueprint.primary_model'),
        );

        $authoritative = $this->app->make(BlueprintImportGroundingResultBuilder::class)->build(
            $providerResult,
            $interpretation,
            $catalog['elements'],
            (int) $profile->profile_version_id,
            hash('sha256', (string) $import->getRawOriginal('interpretation_result')),
            [
                'material_content_hash' => (string) $import->material_content_hash,
                'material_file_hash' => $import->material_file_hash,
                'extractor_implementation' => (string) $import->extractor_implementation,
            ],
            ['prompt_version' => BlueprintImportGroundingPromptBuilder::V1],
        )->toArray();

        $this->assertSame('blueprint-import-grounding-result-v1', $authoritative['schema_version']);
        $this->assertSame($profile->profile_version_id, $authoritative['grounded_profile_version_id']);

        $objective = $authoritative['candidates'][0]['fields']['objective'];
        $this->assertSame('grounded', $objective['status']);
        $evidence = $objective['material_evidence'] ?? [];
        $this->assertNotEmpty($evidence);
        $this->assertTrue(
            collect($evidence)->contains(
                fn (array $item): bool => (int) $item['profile_element_id'] === (int) $element->profile_element_id,
            ),
            'Authoritative evidence must reference the expected pinned Profile element.',
        );

        foreach ($evidence as $item) {
            $this->assertArrayHasKey($item['profile_element_id'], $catalog['elements']);
            $serverElement = $catalog['elements'][$item['profile_element_id']];
            $this->assertSame($serverElement->evidence_excerpt, $item['evidence_excerpt']);
            $this->assertSame($serverElement->evidence_locator, $item['evidence_locator']);
        }

        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertSame(0, QuestionBlueprint::query()->count());
        $this->assertSame(0, QuestionBlueprintRow::query()->count());
        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, QuestionSet::query()->count());
    }
}
