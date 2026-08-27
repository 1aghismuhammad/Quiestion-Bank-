<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialIndexWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_sees_only_own_active_materials(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $own = Material::factory()->text()->for($owner)->create(['title' => 'Owner lesson']);
        $foreign = Material::factory()->text()->for($stranger)->create(['title' => 'Secret lesson']);
        $archived = Material::factory()->text()->for($owner)->archived()->create(['title' => 'Old lesson']);
        $deleted = Material::factory()->text()->for($owner)->create(['title' => 'Deleted lesson']);
        $deleted->delete();

        $this->actingAs($owner)
            ->get(route('materials.index'))
            ->assertOk()
            ->assertSee('Owner lesson')
            ->assertDontSee('Secret lesson')
            ->assertDontSee('Old lesson')
            ->assertDontSee('Deleted lesson')
            ->assertSee($own->title)
            ->assertDontSee($foreign->title);
    }

    public function test_archived_index_shows_only_owner_archived_materials(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        Material::factory()->text()->for($owner)->create(['title' => 'Active lesson']);
        Material::factory()->text()->for($owner)->archived()->create(['title' => 'Archived lesson']);
        Material::factory()->text()->for($stranger)->archived()->create(['title' => 'Foreign archive']);

        $this->actingAs($owner)
            ->get(route('materials.archived'))
            ->assertOk()
            ->assertSee('Archived lesson')
            ->assertDontSee('Active lesson')
            ->assertDontSee('Foreign archive');
    }

    public function test_guests_are_redirected_from_materials_index(): void
    {
        $this->get(route('materials.index'))
            ->assertRedirect(route('login'));
    }

    public function test_incomplete_profile_is_redirected_from_materials_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('materials.index'))
            ->assertRedirect(route('profile.setup'));
    }

    public function test_empty_index_shows_empty_state(): void
    {
        $owner = $this->createCompleteUser();

        $this->actingAs($owner)
            ->get(route('materials.index'))
            ->assertOk()
            ->assertSee('Belum ada materi');
    }
}
