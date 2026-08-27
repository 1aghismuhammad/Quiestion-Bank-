<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Actions\Materials\CreateMaterialTopic;
use App\Actions\Materials\DeleteMaterialTopic;
use App\Actions\Materials\ListMaterialTopics;
use App\Actions\Materials\UpdateMaterialTopic;
use App\Enums\RoleName;
use App\Models\Material;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_update_and_manage_topics_on_own_material(): void
    {
        $owner = User::factory()->create();
        $material = Material::factory()->for($owner)->create();

        $this->assertTrue($owner->can('view', $material));
        $this->assertTrue($owner->can('update', $material));
        $this->assertTrue($owner->can('manageTopics', $material));
        $this->assertTrue($owner->can('viewAny', Material::class));
        $this->assertTrue($owner->can('create', Material::class));
    }

    public function test_non_owner_cannot_view_or_mutate_another_users_material(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $material = Material::factory()->for($owner)->create();

        $this->assertFalse($stranger->can('view', $material));
        $this->assertFalse($stranger->can('update', $material));
        $this->assertFalse($stranger->can('manageTopics', $material));
    }

    public function test_admin_cannot_access_another_users_material(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN->value)->firstOrFail());
        $material = Material::factory()->for($owner)->create();

        $this->assertFalse($admin->can('view', $material));
        $this->assertFalse($admin->can('update', $material));
        $this->assertFalse($admin->can('manageTopics', $material));
    }

    public function test_admin_can_access_own_material(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN->value)->firstOrFail());
        $material = Material::factory()->for($admin)->create();

        $this->assertTrue($admin->can('view', $material));
        $this->assertTrue($admin->can('update', $material));
        $this->assertTrue($admin->can('manageTopics', $material));
    }

    public function test_non_owner_cannot_create_a_topic_for_another_users_material(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $material = Material::factory()->for($owner)->create();

        try {
            (new CreateMaterialTopic)->handle($stranger, $material, [
                'topic_name' => 'Injected topic',
            ]);
            $this->fail('Expected non-owners to be denied topic creation.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('material_topics', 0);
        }
    }

    public function test_non_owner_cannot_list_update_or_delete_another_users_topic(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $material = Material::factory()->for($owner)->create();
        $topic = (new CreateMaterialTopic)->handle($owner, $material, [
            'topic_name' => 'Owned topic',
            'focus_area' => 'Secret focus',
        ]);

        try {
            (new ListMaterialTopics)->handle($stranger, $material);
            $this->fail('Expected non-owners to be denied topic listing.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('material_topics', [
                'topic_id' => $topic->topic_id,
                'topic_name' => 'Owned topic',
            ]);
        }

        try {
            (new UpdateMaterialTopic)->handle($stranger, $material, $topic, [
                'topic_name' => 'Hijacked',
            ]);
            $this->fail('Expected non-owners to be denied topic updates.');
        } catch (AuthorizationException) {
            $this->assertSame('Owned topic', $topic->fresh()->topic_name);
        }

        try {
            (new DeleteMaterialTopic)->handle($stranger, $material, $topic);
            $this->fail('Expected non-owners to be denied topic deletion.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('material_topics', ['topic_id' => $topic->topic_id]);
        }
    }

    public function test_topic_from_another_material_cannot_be_mutated_through_an_owned_material(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownedMaterial = Material::factory()->for($owner)->create();
        $foreignMaterial = Material::factory()->for($other)->create();
        $foreignTopic = (new CreateMaterialTopic)->handle($other, $foreignMaterial, [
            'topic_name' => 'Foreign topic',
        ]);

        try {
            (new UpdateMaterialTopic)->handle($owner, $ownedMaterial, $foreignTopic, [
                'topic_name' => 'Stolen name',
            ]);
            $this->fail('Expected cross-material topic updates to be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('Foreign topic', $foreignTopic->fresh()->topic_name);
            $this->assertSame($foreignMaterial->material_id, $foreignTopic->fresh()->material_id);
        }

        try {
            (new DeleteMaterialTopic)->handle($owner, $ownedMaterial, $foreignTopic);
            $this->fail('Expected cross-material topic deletes to be denied.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('material_topics', ['topic_id' => $foreignTopic->topic_id]);
        }
    }

    public function test_soft_deleted_material_is_not_viewable_or_manageable(): void
    {
        $owner = User::factory()->create();
        $material = Material::factory()->for($owner)->create();
        $topic = (new CreateMaterialTopic)->handle($owner, $material, [
            'topic_name' => 'Before delete',
        ]);

        $material->delete();
        $trashed = Material::withTrashed()->findOrFail($material->material_id);

        $this->assertFalse($owner->can('view', $trashed));
        $this->assertFalse($owner->can('update', $trashed));
        $this->assertFalse($owner->can('manageTopics', $trashed));
        $this->assertNull(Material::query()->find($material->material_id));

        try {
            (new CreateMaterialTopic)->handle($owner, $trashed, [
                'topic_name' => 'After delete',
            ]);
            $this->fail('Expected topic creation on soft-deleted material to be denied.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('material_topics', 1);
            $this->assertDatabaseHas('material_topics', ['topic_id' => $topic->topic_id]);
        }

        try {
            (new UpdateMaterialTopic)->handle($owner, $trashed, $topic, [
                'topic_name' => 'Should not persist',
            ]);
            $this->fail('Expected topic updates on soft-deleted material to be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('Before delete', $topic->fresh()->topic_name);
        }
    }

    public function test_guessed_material_id_does_not_grant_topic_access(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $material = Material::factory()->for($owner)->create();
        $topic = (new CreateMaterialTopic)->handle($owner, $material, [
            'topic_name' => 'Hidden topic',
        ]);

        $guessed = Material::query()->find($material->material_id);

        $this->assertTrue($guessed->is($material));
        $this->assertFalse($stranger->can('view', $guessed));
        $this->assertFalse($stranger->can('manageTopics', $guessed));

        try {
            (new UpdateMaterialTopic)->handle($stranger, $guessed, $topic, [
                'topic_name' => 'Guessed update',
            ]);
            $this->fail('Expected guessed material_id updates to be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('Hidden topic', $topic->fresh()->topic_name);
        }
    }
}
