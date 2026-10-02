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
            ->assertSee('Pembuatan soal')
            ->assertDontSee('Riwayat generasi')
            ->assertSee('Owner history material')
            ->assertDontSee('Foreign history material')
            ->assertSee('Menunggu diproses')
            ->assertDontSee('queued')
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

    public function test_empty_history_points_at_materials(): void
    {
        $owner = $this->createCompleteUser();

        $this->actingAs($owner)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertSee('Pembuatan soal')
            ->assertSee('Belum ada pembuatan soal.')
            ->assertSee('Pilih materi')
            ->assertSee(route('materials.index', absolute: false), false);
    }

    public function test_dashboard_links_to_materials_and_generation_history(): void
    {
        $user = $this->createCompleteUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kelola materi')
            ->assertSee(route('materials.index', absolute: false), false)
            ->assertDontSee('Generate Question')
            ->assertDontSee('Material Management')
            ->assertDontSee('Segera hadir pada phase berikutnya.');
    }

    public function test_history_presents_supporting_copy_columns_and_open_links(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['title' => 'Presented history material']);
        $generation = $this->startGeneration($owner, $material, questionCount: 4);

        $html = $this->actingAs($owner)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertSee('Riwayat pembuatan soal langsung dari materi.')
            ->assertSee('legacy-generation-history-page', false)
            ->assertSee('<th>Jumlah soal</th>', false)
            ->assertSee('<th>Bahasa</th>', false)
            ->assertSee('<th>Antrian</th>', false)
            ->assertSee('<th>Buka</th>', false)
            ->assertSee(route('generations.show', $generation), false)
            ->assertDontSee('Menampilkan')
            ->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
    }
}
