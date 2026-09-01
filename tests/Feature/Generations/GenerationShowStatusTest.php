<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Enums\RoleName;
use App\Models\AiGeneration;
use App\Models\Material;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\TestCase;

class GenerationShowStatusTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    private const PARTIAL_MARKER = 'PHASE45_PARTIAL_MARKER_UNIQUE_ZX9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_owner_can_view_queued_processing_completed_and_failed_states(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['title' => 'Owned material']);

        $queued = $this->generation($owner, $material, GenerationStatus::QUEUED);
        $processing = $this->generation($owner, $material, GenerationStatus::PROCESSING, 'secret-execution-token');
        $completed = $this->generation($owner, $material, GenerationStatus::COMPLETED);
        $failed = $this->generation($owner, $material, GenerationStatus::FAILED);

        $this->actingAs($owner)
            ->get(route('generations.show', $queued))
            ->assertOk()
            ->assertSee('queued')
            ->assertSee('Owned material')
            ->assertSee('multiple_choice')
            ->assertSee('Muat ulang')
            ->assertSee('aria-live="polite"', false)
            ->assertSee('data.generation_status !== initialStatus', false)
            ->assertSee('var initialStatus', false)
            ->assertDontSee(self::PARTIAL_MARKER)
            ->assertDontSee('secret-execution-token')
            ->assertDontSee('Simpan ke Question Bank');

        $this->actingAs($owner)
            ->get(route('generations.show', $processing))
            ->assertOk()
            ->assertSee('processing')
            ->assertSee('data.generation_status !== initialStatus', false)
            ->assertDontSee(self::PARTIAL_MARKER)
            ->assertDontSee('secret-execution-token')
            ->assertDontSee('Coba lagi');

        $this->actingAs($owner)
            ->get(route('generations.show', $completed))
            ->assertOk()
            ->assertSee('completed')
            ->assertSee('Visible completed stem')
            ->assertSee('Option A for Visible completed stem')
            ->assertSee('Jawaban benar')
            ->assertSee('Penjelasan')
            ->assertDontSee('data.generation_status !== initialStatus', false)
            ->assertDontSee(self::PARTIAL_MARKER)
            ->assertDontSee('Coba lagi');

        $this->actingAs($owner)
            ->get(route('generations.show', $failed))
            ->assertOk()
            ->assertSee('failed')
            ->assertSee('Generasi gagal aman.')
            ->assertSee('Coba lagi')
            ->assertDontSee(self::PARTIAL_MARKER)
            ->assertDontSee('provider-debug-stack');
    }

    public function test_cross_user_generation_show_and_status_are_not_found(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $generation = $this->startGeneration($owner);

        $this->actingAs($stranger)
            ->get(route('generations.show', $generation))
            ->assertNotFound()
            ->assertDontSee($generation->material->title);

        $this->actingAs($stranger)
            ->getJson(route('generations.status', $generation))
            ->assertNotFound();
    }

    public function test_admin_cannot_view_another_users_generation(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->createCompleteUser();
        $admin = $this->createCompleteAdmin();
        $generation = $this->startGeneration($owner);

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->assertNotNull(Role::query()->where('role_name', RoleName::ADMIN->value)->first());

        $this->actingAs($admin)
            ->get(route('generations.show', $generation))
            ->assertNotFound();

        $this->actingAs($admin)
            ->getJson(route('generations.status', $generation))
            ->assertNotFound();
    }

    public function test_status_payload_contains_only_safe_fields(): void
    {
        $owner = $this->createCompleteUser();
        $queued = $this->startGeneration($owner);
        $failed = $this->generation($owner, $queued->material, GenerationStatus::FAILED);

        $this->actingAs($owner)
            ->getJson(route('generations.status', $queued))
            ->assertOk()
            ->assertExactJson([
                'generation_status' => 'queued',
                'terminal' => false,
            ]);

        $this->actingAs($owner)
            ->getJson(route('generations.status', $failed))
            ->assertOk()
            ->assertExactJson([
                'generation_status' => 'failed',
                'terminal' => true,
            ]);

        $payload = $this->actingAs($owner)
            ->getJson(route('generations.status', $queued))
            ->json();

        $this->assertSame(['generation_status', 'terminal'], array_keys($payload));
        $this->assertArrayNotHasKey('result_json', $payload);
        $this->assertArrayNotHasKey('execution_token', $payload);
        $this->assertArrayNotHasKey('provider_name', $payload);
        $this->assertArrayNotHasKey('model_name', $payload);
        $this->assertArrayNotHasKey('error_message', $payload);
    }

    public function test_guest_cannot_read_show_or_status(): void
    {
        $generation = $this->startGeneration($this->createCompleteUser());

        $this->get(route('generations.show', $generation))
            ->assertRedirect(route('login'));

        $this->getJson(route('generations.status', $generation))
            ->assertUnauthorized();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function partialResult(): array
    {
        $question = GeminiFakeResponses::question(self::PARTIAL_MARKER);
        $question['explanation'] = 'provider-debug-stack '.self::PARTIAL_MARKER;

        return [$question];
    }

    private function generation(
        User $owner,
        Material $material,
        GenerationStatus $status,
        ?string $token = null,
    ): AiGeneration {
        $attributes = [
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'generation_status' => $status,
            'result_json' => $this->partialResult(),
            'execution_token' => $token,
            'error_message' => $status === GenerationStatus::FAILED ? 'Generasi gagal aman.' : null,
        ];

        if ($status === GenerationStatus::COMPLETED) {
            $attributes['result_json'] = [GeminiFakeResponses::question('Visible completed stem')];
        }

        return AiGeneration::factory()->create($attributes);
    }
}
