<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Enums\GenerationStatus;
use App\Enums\QuestionSetStatus;
use App\Enums\RoleName;
use App\Models\AiGeneration;
use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\Role;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Generations\StartsQuestionGenerations;
use Tests\TestCase;

class QuestionSetIndexShowTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_index_is_owner_scoped_with_empty_state_and_pagination(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        QuestionSet::factory()->for($stranger)->create(['title' => 'Foreign bank title']);

        $this->actingAs($owner)
            ->get(route('question-sets.index'))
            ->assertOk()
            ->assertSee('Belum ada soal di Question Bank')
            ->assertDontSee('Foreign bank title');

        QuestionSet::factory()->for($owner)->count(15)->create();
        QuestionSet::factory()->for($owner)->create(['title' => 'Visible bank set']);

        $this->actingAs($owner)
            ->get(route('question-sets.index'))
            ->assertOk()
            ->assertSee('Visible bank set')
            ->assertDontSee('Foreign bank title')
            ->assertSee('Berikutnya');

        $this->actingAs($owner)
            ->get(route('question-sets.index', ['page' => 2]))
            ->assertOk();

        $this->assertSame(16, $owner->questionSets()->count());
        $this->assertSame(1, $stranger->questionSets()->count());
    }

    public function test_show_renders_mcq_and_hides_unsafe_fields(): void
    {
        $owner = $this->createCompleteUser();
        $set = QuestionSet::factory()->for($owner)->create([
            'title' => 'Bank detail',
            'status' => QuestionSetStatus::DRAFT,
            'total_question' => 1,
        ]);
        $question = Question::factory()->for($set, 'questionSet')->create([
            'question_number' => 1,
            'question_text' => 'Visible bank stem',
            'explanation' => 'Because the material supports it',
        ]);
        foreach (['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4] as $label => $order) {
            QuestionOption::factory()->for($question)->create([
                'option_label' => $label,
                'option_text' => 'Option '.$label.' for Visible bank stem',
                'sort_order' => $order,
                'is_correct' => $label === 'B',
            ]);
        }

        $this->actingAs($owner)
            ->get(route('question-sets.show', $set))
            ->assertOk()
            ->assertSee('Bank detail')
            ->assertSee('draft')
            ->assertSee('Visible bank stem')
            ->assertSee('Option A for Visible bank stem')
            ->assertSee('Option B for Visible bank stem')
            ->assertSee('Jawaban benar')
            ->assertSee('Because the material supports it')
            ->assertDontSee('Simpan ke Question Bank')
            ->assertDontSee('execution_token')
            ->assertSee('Edit')
            ->assertSee('Terbitkan')
            ->assertDontSee('Hapus');
    }

    public function test_stranger_and_admin_cannot_show_foreign_set(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $admin = $this->createCompleteAdmin();
        $set = QuestionSet::factory()->for($owner)->create(['title' => 'Secret bank']);

        $this->actingAs($stranger)
            ->get(route('question-sets.show', $set))
            ->assertNotFound()
            ->assertDontSee('Secret bank');

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->assertNotNull(Role::query()->where('role_name', RoleName::ADMIN->value)->first());

        $this->actingAs($admin)
            ->get(route('question-sets.show', $set))
            ->assertNotFound()
            ->assertDontSee('Secret bank');
    }

    public function test_dashboard_and_nav_link_to_question_bank(): void
    {
        $user = $this->createCompleteUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Question Bank')
            ->assertSee(route('question-sets.index', absolute: false), false)
            ->assertDontSee('Segera hadir pada phase berikutnya.');

        $this->actingAs($user)
            ->get(route('question-sets.index'))
            ->assertOk();
    }

    public function test_queued_processing_and_failed_generation_do_not_show_save_cta(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $queued = AiGeneration::factory()->for($owner)->for($material)->create([
            'generation_status' => GenerationStatus::QUEUED,
        ]);
        $processing = AiGeneration::factory()->for($owner)->for($material)->create([
            'generation_status' => GenerationStatus::PROCESSING,
        ]);
        $failed = AiGeneration::factory()->for($owner)->for($material)->create([
            'generation_status' => GenerationStatus::FAILED,
        ]);

        $this->actingAs($owner)
            ->get(route('generations.show', $queued))
            ->assertDontSee('Simpan ke Question Bank');

        $this->actingAs($owner)
            ->get(route('generations.show', $processing))
            ->assertDontSee('Simpan ke Question Bank');

        $this->actingAs($owner)
            ->get(route('generations.show', $failed))
            ->assertDontSee('Simpan ke Question Bank');
    }
}
