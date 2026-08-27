<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Models\Material;
use App\Models\MaterialTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialTopicWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_updates_and_deletes_topics_through_http(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('materials.topics.store', $material), [
                'topic_name' => 'Fotosintesis',
                'focus_area' => 'Cahaya',
                'chapter' => '1',
                'sub_chapter' => '2',
                'sort_order' => 2,
                'page_start' => 4,
                'page_end' => 8,
            ])
            ->assertRedirect(route('materials.show', $material));

        $topic = MaterialTopic::query()->firstOrFail();
        $this->assertSame('Fotosintesis', $topic->topic_name);
        $this->assertSame(4, $topic->page_start);

        $this->actingAs($owner)
            ->patch(route('materials.topics.update', [$material, $topic]), [
                'topic_name' => 'Fotosintesis revisi',
                'focus_area' => 'Cahaya',
                'chapter' => '1',
                'sub_chapter' => '2',
                'sort_order' => 2,
                'page_start' => 4,
                'page_end' => 10,
            ])
            ->assertRedirect(route('materials.show', $material));

        $this->assertSame('Fotosintesis revisi', $topic->fresh()->topic_name);
        $this->assertSame(10, $topic->fresh()->page_end);

        $this->actingAs($owner)
            ->delete(route('materials.topics.destroy', [$material, $topic]))
            ->assertRedirect(route('materials.show', $material));

        $this->assertDatabaseCount('material_topics', 0);
    }

    public function test_topics_render_in_sort_order_then_topic_id(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        MaterialTopic::factory()->for($material, 'material')->create([
            'topic_name' => 'Second',
            'sort_order' => 2,
        ]);
        MaterialTopic::factory()->for($material, 'material')->create([
            'topic_name' => 'First',
            'sort_order' => 1,
        ]);

        $this->actingAs($owner)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSeeInOrder(['First', 'Second']);
    }

    public function test_invalid_page_range_and_duplicate_topic_return_validation_errors(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('materials.topics.store', $material), [
                'topic_name' => 'Range',
                'page_start' => 10,
                'page_end' => 2,
            ])
            ->assertSessionHasErrors('page_end');

        $this->actingAs($owner)
            ->post(route('materials.topics.store', $material), [
                'topic_name' => 'Photosynthesis',
                'chapter' => '1',
                'sub_chapter' => '2',
            ])
            ->assertRedirect(route('materials.show', $material));

        $this->actingAs($owner)
            ->post(route('materials.topics.store', $material), [
                'topic_name' => 'Photosynthesis',
                'chapter' => '1',
                'sub_chapter' => '2',
            ])
            ->assertSessionHasErrors('topic_name');

        $this->assertDatabaseCount('material_topics', 1);
    }
}
