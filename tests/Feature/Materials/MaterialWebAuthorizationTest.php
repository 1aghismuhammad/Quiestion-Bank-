<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\ArchiveMaterial;
use App\Enums\MaterialStatus;
use App\Models\Material;
use App\Models\MaterialTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialWebAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_owner_cannot_view_update_archive_restore_or_manage_topics(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'title' => 'Private lesson',
            'content' => 'Secret body',
        ]);
        $topic = MaterialTopic::factory()->for($material, 'material')->create([
            'topic_name' => 'Private topic',
        ]);

        $this->actingAs($stranger)
            ->get(route('materials.show', $material))
            ->assertForbidden()
            ->assertDontSee('Private lesson')
            ->assertDontSee('Secret body');

        $this->actingAs($stranger)
            ->get(route('materials.edit', $material))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->patch(route('materials.update', $material), [
                'title' => 'Hijacked',
                'content' => 'Stolen',
            ])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('materials.archive', $material))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('materials.topics.store', $material), [
                'topic_name' => 'Injected',
            ])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->patch(route('materials.topics.update', [$material, $topic]), [
                'topic_name' => 'Hijacked topic',
            ])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->delete(route('materials.topics.destroy', [$material, $topic]))
            ->assertForbidden();

        $material->refresh();
        $this->assertSame('Private lesson', $material->title);
        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertDatabaseHas('material_topics', [
            'topic_id' => $topic->topic_id,
            'topic_name' => 'Private topic',
        ]);

        (new ArchiveMaterial)->handle($owner, $material);

        $this->actingAs($stranger)
            ->post(route('materials.restore', $material))
            ->assertForbidden();

        $this->assertSame(MaterialStatus::ARCHIVED, $material->fresh()->status);
    }

    public function test_cross_material_topic_id_is_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $other = $this->createCompleteUser();
        $owned = Material::factory()->text()->for($owner)->create();
        $foreignMaterial = Material::factory()->text()->for($other)->create();
        $foreignTopic = MaterialTopic::factory()->for($foreignMaterial, 'material')->create([
            'topic_name' => 'Foreign topic',
        ]);

        $this->actingAs($owner)
            ->patch(route('materials.topics.update', [$owned, $foreignTopic]), [
                'topic_name' => 'Stolen name',
            ])
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete(route('materials.topics.destroy', [$owned, $foreignTopic]))
            ->assertNotFound();

        $this->assertSame('Foreign topic', $foreignTopic->fresh()->topic_name);
        $this->assertDatabaseHas('material_topics', ['topic_id' => $foreignTopic->topic_id]);
    }

    public function test_soft_deleted_material_is_not_accessible_by_url(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'title' => 'Trashed lesson',
        ]);
        $id = $material->material_id;
        $material->delete();

        $this->actingAs($owner)
            ->get('/materials/'.$id)
            ->assertNotFound();
    }

    public function test_authorization_failure_does_not_mutate_topic_or_material(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'title' => 'Stable title',
        ]);

        $this->actingAs($stranger)
            ->patch(route('materials.update', $material), [
                'title' => 'Changed',
                'content' => 'Changed',
            ])
            ->assertForbidden();

        $this->assertSame('Stable title', $material->fresh()->title);
        $this->assertDatabaseCount('materials', 1);
    }
}
