<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\TestCase;

class MaterialProfileIsolationTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;

    public function test_queue_does_not_write_usage_logs_or_add_http_routes(): void
    {
        $usageBefore = AiUsageLog::query()->count();
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Tanpa kuota.']);

        $this->queueProfile($user, $material);

        $this->assertSame($usageBefore, AiUsageLog::query()->count());

        Artisan::call('route:list');
        $listed = Artisan::output();

        $this->assertStringNotContainsString('MaterialProfile', $listed);
        $this->assertStringNotContainsString('profiles.analyze', $listed);
        $this->assertStringNotContainsString('store-profile', $listed);
        $this->assertStringNotContainsString('materials.store-text', file_get_contents(base_path('routes/web.php')));
        $this->assertStringContainsString('profiles:recover-stale', file_get_contents(base_path('routes/console.php')));
    }

    public function test_legacy_text_material_lifecycle_is_unchanged(): void
    {
        $user = $this->createCompleteUser();
        $material = Material::factory()->text()->for($user)->create([
            'title' => 'Teks lama',
            'content' => 'Isi lama',
        ]);

        $this->actingAs($user)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Teks lama');

        $this->actingAs($user)
            ->patch(route('materials.update', $material), [
                'title' => 'Teks lama diubah',
                'content' => 'Isi baru',
            ])
            ->assertRedirect();

        $this->assertSame('Isi baru', $material->fresh()->content);
    }
}
