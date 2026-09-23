<?php

declare(strict_types=1);

namespace Tests\Unit\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportGroundingCatalogEntry;
use App\Enums\MaterialProfileElementOrigin;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Services\QuestionBlueprints\BlueprintImportGroundingCatalogBuilder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class BlueprintImportGroundingCatalogBuilderTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    private BlueprintImportGroundingCatalogBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->builder = $this->app->make(BlueprintImportGroundingCatalogBuilder::class);
    }

    public function test_catalog_contains_only_extracted_elements_in_stable_order(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['content' => 'Materi katalog']);
        $profile = $this->readyProfile($owner, $material);
        $existing = MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->firstOrFail();
        $existing->update(['sort_order' => 2, 'text' => 'Kedua']);
        $first = MaterialProfileElement::factory()->extracted()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $existing->source_chunk_id,
            'sort_order' => 1,
            'text' => 'Pertama',
        ]);
        MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'origin' => MaterialProfileElementOrigin::SUGGESTED,
            'sort_order' => 0,
            'text' => 'Tidak boleh masuk',
        ]);

        $built = $this->builder->build($profile);

        $this->assertSame([$first->profile_element_id, $existing->profile_element_id], array_map(
            static fn (BlueprintImportGroundingCatalogEntry $entry): int => $entry->profileElementId,
            $built['catalog'],
        ));
        $this->assertSame(['Pertama', 'Kedua'], array_map(
            static fn (BlueprintImportGroundingCatalogEntry $entry): string => $entry->text,
            $built['catalog'],
        ));
        $this->assertSame([$first->profile_element_id, $existing->profile_element_id], array_keys($built['elements']));
    }

    public function test_catalog_rejects_more_than_two_hundred_extracted_elements(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $profile = $this->readyProfile($owner, $material);
        MaterialProfileElement::factory()->count(200)->extracted()->create([
            'profile_version_id' => $profile->profile_version_id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(BlueprintImportGroundingCatalogBuilder::ERROR_INPUT_TOO_LARGE);
        $this->builder->build($profile);
    }

    public function test_request_serialization_is_canonical_hashed_and_byte_bounded(): void
    {
        $catalog = [new BlueprintImportGroundingCatalogEntry(7, 'topic', 'Fotosintesis')];
        $serialized = $this->builder->serializeRequest(
            [['index' => 2, 'claims' => ['objective' => 'Jelaskan fotosintesis']]],
            $catalog,
        );

        $this->assertSame(hash('sha256', $serialized['json']), $serialized['hash']);
        $this->assertSame([
            'candidates' => [['index' => 2, 'claims' => ['objective' => 'Jelaskan fotosintesis']]],
            'catalog' => [['profile_element_id' => 7, 'kind' => 'topic', 'text' => 'Fotosintesis']],
        ], json_decode($serialized['json'], true, flags: JSON_THROW_ON_ERROR));

        config(['question_blueprint.import_grounding_max_request_bytes' => 10]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(BlueprintImportGroundingCatalogBuilder::ERROR_INPUT_TOO_LARGE);
        $this->builder->serializeRequest(
            [['index' => 0, 'claims' => ['objective' => str_repeat('x', 50)]]],
            [],
        );
    }
}
