<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Enums\MaterialStatus;
use App\Enums\QuestionType;
use App\Enums\UsageStatus;
use App\Enums\UserStatus;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerationCreateStoreTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_guest_is_redirected_from_create_and_store(): void
    {
        $material = Material::factory()->text()->create();

        $this->get(route('generations.create', $material))
            ->assertRedirect(route('login'));

        $this->post(route('generations.store', $material), $this->payload())
            ->assertRedirect(route('login'));
    }

    public function test_incomplete_profile_is_redirected_from_create(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();

        $this->actingAs($user)
            ->get(route('generations.create', $material))
            ->assertRedirect(route('profile.setup'));
    }

    public function test_inactive_account_is_forbidden_from_create(): void
    {
        $user = $this->createCompleteUser(['status' => UserStatus::SUSPENDED]);
        $material = Material::factory()->text()->for($user)->create();

        $this->actingAs($user)
            ->get(route('generations.create', $material))
            ->assertForbidden();
    }

    public function test_owner_can_open_create_form_with_defaults_and_quota_summary(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['title' => 'Ready lesson']);

        $this->actingAs($owner)
            ->get(route('generations.create', $material))
            ->assertOk()
            ->assertSee('Ready lesson')
            ->assertSee('Pilihan ganda')
            ->assertSee('Bahasa Indonesia')
            ->assertSee('English')
            ->assertSee('value="5"', false)
            ->assertSee('value="id"', false)
            ->assertSee('Terpakai')
            ->assertSee('Diproses')
            ->assertSee('Tersedia')
            ->assertDontSee('true_false')
            ->assertDontSee('essay')
            ->assertDontSee('Simpan ke Question Bank')
            ->assertDontSee('Import ke Question Bank');
    }

    public function test_cross_user_material_create_and_store_are_forbidden(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['title' => 'Private generate']);

        $this->actingAs($stranger)
            ->get(route('generations.create', $material))
            ->assertForbidden()
            ->assertDontSee('Private generate');

        $this->actingAs($stranger)
            ->post(route('generations.store', $material), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, AiGeneration::query()->count());
    }

    public function test_archived_and_ineligible_materials_cannot_generate(): void
    {
        $owner = $this->createCompleteUser();
        $archived = Material::factory()->text()->for($owner)->archived()->create([
            'title' => 'Archived lesson',
        ]);
        $pending = Material::factory()->upload()->for($owner)->create([
            'title' => 'Pending upload',
        ]);

        $this->actingAs($owner)
            ->get(route('materials.show', $archived))
            ->assertOk()
            ->assertDontSee('Generate Questions');

        $this->actingAs($owner)
            ->get(route('generations.create', $archived))
            ->assertRedirect(route('materials.show', $archived));

        $this->actingAs($owner)
            ->from(route('generations.create', $archived))
            ->post(route('generations.store', $archived), $this->payload())
            ->assertRedirect(route('generations.create', $archived))
            ->assertSessionHasErrors('material');

        $this->actingAs($owner)
            ->get(route('materials.show', $pending))
            ->assertOk()
            ->assertDontSee('Generate Questions');

        $this->actingAs($owner)
            ->get(route('generations.create', $pending))
            ->assertRedirect(route('materials.show', $pending));

        $this->assertSame(0, AiGeneration::query()->count());
        $this->assertSame(MaterialStatus::ARCHIVED, $archived->fresh()->status);
    }

    public function test_ready_material_shows_generate_cta(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();

        $this->actingAs($owner)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Generate Questions')
            ->assertDontSee('Simpan ke Question Bank');
    }

    public function test_store_rejects_non_mcq_and_invalid_count_and_language(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();

        $this->actingAs($owner)
            ->from(route('generations.create', $material))
            ->post(route('generations.store', $material), $this->payload([
                'question_type' => QuestionType::ESSAY->value,
            ]))
            ->assertRedirect(route('generations.create', $material))
            ->assertSessionHasErrors('question_type');

        $this->actingAs($owner)
            ->from(route('generations.create', $material))
            ->post(route('generations.store', $material), $this->payload([
                'question_type' => QuestionType::TRUE_FALSE->value,
            ]))
            ->assertSessionHasErrors('question_type');

        $this->actingAs($owner)
            ->from(route('generations.create', $material))
            ->post(route('generations.store', $material), $this->payload([
                'question_count' => 0,
            ]))
            ->assertSessionHasErrors('question_count');

        $this->actingAs($owner)
            ->from(route('generations.create', $material))
            ->post(route('generations.store', $material), $this->payload([
                'question_count' => 11,
            ]))
            ->assertSessionHasErrors('question_count');

        $this->actingAs($owner)
            ->from(route('generations.create', $material))
            ->post(route('generations.store', $material), $this->payload([
                'output_language' => 'fr',
            ]))
            ->assertSessionHasErrors('output_language');

        $this->assertSame(0, AiGeneration::query()->count());
    }

    public function test_store_accepts_id_and_en_and_dispatches_queued_generation(): void
    {
        Queue::fake([GenerateQuestionsJob::class]);

        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('generations.store', $material), $this->payload([
                'output_language' => 'en',
                'question_count' => 1,
            ]))
            ->assertRedirect();

        $generation = AiGeneration::query()->firstOrFail();
        $this->assertSame($owner->id, $generation->user_id);
        $this->assertSame(1, $generation->question_count);
        $this->assertSame('en', $generation->output_language?->value);
        $this->assertSame('queued', $generation->generation_status->value);
        $this->assertSame(UsageStatus::RESERVED, $generation->usageLog->status);

        $this->actingAs($owner)
            ->post(route('generations.store', $material), $this->payload([
                'output_language' => 'id',
            ]))
            ->assertRedirect(route('generations.show', AiGeneration::query()->latest('generation_id')->first()));

        Queue::assertPushed(GenerateQuestionsJob::class, 2);
    }

    public function test_quota_exhausted_store_is_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->startGeneration($owner, $material);
        $this->startGeneration($owner, $material);

        $this->actingAs($owner)
            ->from(route('generations.create', $material))
            ->post(route('generations.store', $material), $this->payload())
            ->assertRedirect(route('generations.create', $material))
            ->assertSessionHasErrors('quota');

        $this->assertSame(2, AiGeneration::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'assessment_type' => 'formative',
            'difficulty_level' => 'medium',
            'question_type' => 'multiple_choice',
            'question_count' => 5,
            'output_language' => 'id',
        ], $overrides);
    }
}
