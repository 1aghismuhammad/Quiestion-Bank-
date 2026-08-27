<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\CreateMaterialTopic;
use App\Actions\Materials\DeleteMaterialTopic;
use App\Actions\Materials\ListMaterialTopics;
use App\Actions\Materials\UpdateMaterialTopic;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaterialTopicManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_topic_with_canonical_fields(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        $topic = (new CreateMaterialTopic)->handle($user, $material, [
            'topic_name' => 'Fotosintesis',
            'focus_area' => 'Proses cahaya',
            'chapter' => '1',
            'sub_chapter' => '2',
            'sort_order' => 3,
            'page_start' => 4,
            'page_end' => 8,
        ]);

        $this->assertSame($material->material_id, $topic->material_id);
        $this->assertSame('Fotosintesis', $topic->topic_name);
        $this->assertSame('Proses cahaya', $topic->focus_area);
        $this->assertSame('1', $topic->chapter);
        $this->assertSame('2', $topic->sub_chapter);
        $this->assertSame(3, $topic->sort_order);
        $this->assertSame(4, $topic->page_start);
        $this->assertSame(8, $topic->page_end);
        $this->assertTrue($topic->fresh()->material->is($material));
    }

    public function test_empty_chapter_and_sub_chapter_persist_as_empty_strings_and_sort_order_defaults_to_zero(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        $topic = (new CreateMaterialTopic)->handle($user, $material, [
            'topic_name' => 'Introduction',
        ]);

        $this->assertSame('', $topic->chapter);
        $this->assertSame('', $topic->sub_chapter);
        $this->assertSame(0, $topic->sort_order);
        $this->assertNull($topic->focus_area);
        $this->assertNull($topic->page_start);
        $this->assertNull($topic->page_end);
    }

    public function test_topics_are_listed_in_sort_order_then_topic_id(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        $second = (new CreateMaterialTopic)->handle($user, $material, [
            'topic_name' => 'Second',
            'sort_order' => 2,
        ]);
        $first = (new CreateMaterialTopic)->handle($user, $material, [
            'topic_name' => 'First',
            'sort_order' => 1,
        ]);

        $topics = (new ListMaterialTopics)->handle($user, $material);

        $this->assertTrue($topics->first()->is($first));
        $this->assertTrue($topics->last()->is($second));
        $this->assertCount(2, $topics);
    }

    public function test_owner_updates_and_deletes_own_topic(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $topic = (new CreateMaterialTopic)->handle($user, $material, [
            'topic_name' => 'Draft topic',
            'chapter' => '1',
            'page_start' => 2,
            'page_end' => 5,
        ]);

        $updated = (new UpdateMaterialTopic)->handle($user, $material, $topic, [
            'topic_name' => 'Revised topic',
            'focus_area' => 'Updated focus',
            'page_end' => 9,
        ]);

        $this->assertSame('Revised topic', $updated->topic_name);
        $this->assertSame('Updated focus', $updated->focus_area);
        $this->assertSame('1', $updated->chapter);
        $this->assertSame(2, $updated->page_start);
        $this->assertSame(9, $updated->page_end);

        (new DeleteMaterialTopic)->handle($user, $material, $updated);

        $this->assertDatabaseMissing('material_topics', ['topic_id' => $updated->topic_id]);
        $this->assertDatabaseCount('material_topics', 0);
    }

    public function test_topics_remain_isolated_per_material(): void
    {
        $user = User::factory()->create();
        $firstMaterial = Material::factory()->for($user)->create();
        $secondMaterial = Material::factory()->for($user)->create();

        (new CreateMaterialTopic)->handle($user, $firstMaterial, [
            'topic_name' => 'Only on first',
        ]);
        (new CreateMaterialTopic)->handle($user, $secondMaterial, [
            'topic_name' => 'Only on second',
        ]);

        $firstTopics = (new ListMaterialTopics)->handle($user, $firstMaterial);
        $secondTopics = (new ListMaterialTopics)->handle($user, $secondMaterial);

        $this->assertSame(['Only on first'], $firstTopics->pluck('topic_name')->all());
        $this->assertSame(['Only on second'], $secondTopics->pluck('topic_name')->all());
    }

    public function test_missing_topic_name_is_rejected(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        try {
            (new CreateMaterialTopic)->handle($user, $material, [
                'chapter' => '1',
            ]);
            $this->fail('Expected topic_name to be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('topic_name', $exception->errors());
        }

        $this->assertDatabaseCount('material_topics', 0);
    }

    public function test_invalid_page_range_is_rejected(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        try {
            (new CreateMaterialTopic)->handle($user, $material, [
                'topic_name' => 'Range',
                'page_start' => 10,
                'page_end' => 3,
            ]);
            $this->fail('Expected page_end before page_start to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('page_end', $exception->errors());
        }

        $this->assertDatabaseCount('material_topics', 0);
    }

    public function test_page_start_below_one_is_rejected(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        try {
            (new CreateMaterialTopic)->handle($user, $material, [
                'topic_name' => 'Page zero',
                'page_start' => 0,
            ]);
            $this->fail('Expected page_start below 1 to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('page_start', $exception->errors());
        }

        $this->assertDatabaseCount('material_topics', 0);
    }

    public function test_duplicate_topic_path_on_the_same_material_is_rejected_without_a_second_row(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        (new CreateMaterialTopic)->handle($user, $material, [
            'topic_name' => 'Photosynthesis',
            'chapter' => '1',
            'sub_chapter' => '2',
        ]);

        try {
            (new CreateMaterialTopic)->handle($user, $material, [
                'topic_name' => 'Photosynthesis',
                'chapter' => '1',
                'sub_chapter' => '2',
            ]);
            $this->fail('Expected duplicate topic paths to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('topic_name', $exception->errors());
        }

        $this->assertDatabaseCount('material_topics', 1);
    }

    public function test_update_page_range_is_validated_against_existing_values(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $topic = (new CreateMaterialTopic)->handle($user, $material, [
            'topic_name' => 'Range',
            'page_start' => 4,
            'page_end' => 8,
        ]);

        try {
            (new UpdateMaterialTopic)->handle($user, $material, $topic, [
                'page_start' => 12,
            ]);
            $this->fail('Expected updated page_start after existing page_end to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('page_end', $exception->errors());
        }

        $this->assertSame(4, $topic->fresh()->page_start);
        $this->assertSame(8, $topic->fresh()->page_end);
    }
}
