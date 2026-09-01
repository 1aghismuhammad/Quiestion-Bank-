<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Models\AiGeneration;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationHistoryTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_guest_is_redirected_from_history(): void
    {
        $this->get(route('generations.index'))
            ->assertRedirect(route('login'));
    }

    public function test_incomplete_profile_is_redirected_from_history(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertRedirect(route('profile.setup'));
    }

    public function test_owner_sees_only_own_generations_and_pagination(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $ownMaterial = Material::factory()->text()->for($owner)->create(['title' => 'Owner history material']);
        $foreignMaterial = Material::factory()->text()->for($stranger)->create(['title' => 'Foreign history material']);

        $visible = $this->startGeneration($owner, $ownMaterial, questionCount: 3);
        $this->startGeneration($stranger, $foreignMaterial, questionCount: 8);

        for ($i = 0; $i < 15; $i++) {
            AiGeneration::factory()->for($owner)->create([
                'material_id' => $ownMaterial->material_id,
                'question_count' => 1,
            ]);
        }

        $this->actingAs($owner)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertSee('Riwayat generasi')
            ->assertSee('Owner history material')
            ->assertDontSee('Foreign history material')
            ->assertSee('queued')
            ->assertSee('Berikutnya')
            ->assertDontSee('Simpan ke Question Bank');

        $this->actingAs($owner)
            ->get(route('generations.index', ['page' => 2]))
            ->assertOk()
            ->assertSee((string) $visible->question_count)
            ->assertDontSee('Foreign history material');

        $this->assertSame(16, $owner->generations()->count());
        $this->assertSame(1, $stranger->generations()->count());
    }

    public function test_dashboard_links_to_materials_and_generation_history(): void
    {
        $user = $this->createCompleteUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Generate Question')
            ->assertSee(route('materials.index', absolute: false), false)
            ->assertSee('History')
            ->assertSee(route('generations.index', absolute: false), false)
            ->assertSee('Question Bank')
            ->assertSee('Segera hadir pada phase berikutnya.');
    }
}
