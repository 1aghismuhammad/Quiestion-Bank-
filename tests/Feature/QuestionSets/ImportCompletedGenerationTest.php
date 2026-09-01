<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Actions\QuestionSets\ImportCompletedGenerationIntoQuestionSet;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationStatus;
use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewStatus;
use App\Enums\RoleName;
use App\Enums\Visibility;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Generations\StartsQuestionGenerations;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\TestCase;

class ImportCompletedGenerationTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_completed_owned_mcq_imports_a_draft_snapshot(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['title' => 'Materi impor']);
        $generation = $this->completeGeneration($this->startGeneration($owner, $material, questionCount: 2), GeminiFakeResponses::questions(2, 'Impor'));
        $resultJson = $generation->result_json;
        $usageStatus = $generation->usageLog->status;

        $this->actingAs($owner)
            ->from(route('generations.show', $generation))
            ->post(route('question-sets.import', $generation))
            ->assertRedirect(route('question-sets.show', $set = QuestionSet::query()->firstOrFail()));

        $this->assertSame($owner->id, $set->user_id);
        $this->assertSame((int) $generation->generation_id, (int) $set->generation_id);
        $this->assertSame('Materi impor', $set->title);
        $this->assertSame(QuestionSetStatus::DRAFT, $set->status);
        $this->assertSame(ReviewStatus::NOT_SUBMITTED, $set->review_status);
        $this->assertSame(Visibility::PRIVATE, $set->visibility);
        $this->assertSame(2, $set->total_question);
        $this->assertSame(2, Question::query()->count());
        $this->assertSame(8, QuestionOption::query()->count());

        $first = $set->questions()->with('options')->firstOrFail();
        $this->assertSame(1, $first->question_number);
        $this->assertSame('Impor 1', $first->question_text);
        $this->assertSame(QuestionType::MULTIPLE_CHOICE, $first->question_type);
        $this->assertSame(DifficultyLevel::MEDIUM, $first->difficulty_level);
        $this->assertNull($first->correct_answer);
        $this->assertNull($first->rubric);
        $this->assertSame('Because the material supports Impor 1', $first->explanation);
        $this->assertSame(['A', 'B', 'C', 'D'], $first->options->pluck('option_label')->all());
        $this->assertSame([1, 2, 3, 4], $first->options->pluck('sort_order')->all());
        $this->assertSame(1, $first->options->where('is_correct', true)->count());
        $this->assertTrue($first->options->firstWhere('option_label', 'A')->is_correct);

        $generation->refresh();
        $this->assertSame($resultJson, $generation->result_json);
        $this->assertSame(GenerationStatus::COMPLETED, $generation->generation_status);
        $this->assertSame($usageStatus, $generation->usageLog->fresh()->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_id', $generation->generation_id)->count());
    }

    public function test_duplicate_import_reuses_the_same_question_set(): void
    {
        $owner = $this->createCompleteUser();
        $generation = $this->completeGeneration($this->startGeneration($owner, questionCount: 1), GeminiFakeResponses::questions(1));

        $this->actingAs($owner)->post(route('question-sets.import', $generation))->assertRedirect();
        $firstId = (int) QuestionSet::query()->value('question_set_id');

        $this->actingAs($owner)
            ->post(route('question-sets.import', $generation))
            ->assertRedirect(route('question-sets.show', $firstId));

        $this->assertSame(1, QuestionSet::query()->count());
        $this->assertSame(1, Question::query()->count());
        $this->assertSame(4, QuestionOption::query()->count());
        $this->assertSame($firstId, (int) QuestionSet::query()->value('question_set_id'));
    }

    public function test_non_completed_generations_cannot_be_imported(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $queued = $this->factoryGeneration($owner, $material, GenerationStatus::QUEUED);
        $processing = $this->factoryGeneration($owner, $material, GenerationStatus::PROCESSING);
        $failed = $this->factoryGeneration($owner, $material, GenerationStatus::FAILED);
        $cancelled = $this->factoryGeneration($owner, $material, GenerationStatus::CANCELLED);

        foreach ([$queued, $processing, $failed, $cancelled] as $generation) {
            $this->actingAs($owner)
                ->post(route('question-sets.import', $generation))
                ->assertForbidden();
        }

        $this->assertSame(0, QuestionSet::query()->count());
        $this->assertSame(0, Question::query()->count());
        $this->assertSame(0, QuestionOption::query()->count());
    }

    public function test_stranger_and_admin_cannot_import_foreign_generation(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $admin = $this->createCompleteAdmin();
        $generation = $this->completeGeneration($this->startGeneration($owner, questionCount: 1), GeminiFakeResponses::questions(1));

        $this->actingAs($stranger)
            ->post(route('question-sets.import', $generation))
            ->assertNotFound();

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->assertNotNull(Role::query()->where('role_name', RoleName::ADMIN->value)->first());

        $this->actingAs($admin)
            ->post(route('question-sets.import', $generation))
            ->assertNotFound();

        $this->assertSame(0, QuestionSet::query()->count());
    }

    public function test_malformed_and_mismatched_results_are_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();

        $cases = [
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, null, 1),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, [], 1),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, ['not-a-question'], 1),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, [$this->questionWithout('options')]),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, [$this->questionWithOptions(['A' => 'a', 'B' => 'b'])]),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, [$this->invalidAnswer('E')]),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, [$this->questionWithStem('')]),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, [$this->questionWithExplanation('')]),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, [$this->duplicateOptions()]),
            $this->factoryGeneration($owner, $material, GenerationStatus::COMPLETED, GeminiFakeResponses::questions(1), 2),
        ];

        foreach ($cases as $generation) {
            $this->actingAs($owner)
                ->from(route('generations.show', $generation))
                ->post(route('question-sets.import', $generation))
                ->assertRedirect(route('generations.show', $generation))
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, QuestionSet::query()->count());
        $this->assertSame(0, Question::query()->count());
        $this->assertSame(0, QuestionOption::query()->count());
    }

    public function test_non_mcq_completed_generation_is_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $generation = $this->completeGeneration($this->startGeneration($owner, questionCount: 1), GeminiFakeResponses::questions(1));
        $generation->forceFill(['question_type' => QuestionType::TRUE_FALSE])->save();

        $this->actingAs($owner)
            ->from(route('generations.show', $generation))
            ->post(route('question-sets.import', $generation))
            ->assertRedirect(route('generations.show', $generation))
            ->assertSessionHasErrors('question_type');

        $this->assertSame(0, QuestionSet::query()->count());
    }

    public function test_action_rejects_queued_generation_without_http(): void
    {
        $owner = $this->createCompleteUser();
        $queued = $this->startGeneration($owner);

        $this->expectException(ValidationException::class);
        $this->app->make(ImportCompletedGenerationIntoQuestionSet::class)->handle($owner, $queued);
    }

    public function test_empty_material_title_falls_back(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['title' => '   ']);
        $generation = $this->completeGeneration($this->startGeneration($owner, $material, questionCount: 1), GeminiFakeResponses::questions(1));

        $set = $this->app->make(ImportCompletedGenerationIntoQuestionSet::class)->handle($owner, $generation);

        $this->assertSame('Generasi soal', $set->title);
    }

    public function test_completed_show_has_save_cta_then_view_cta_after_import(): void
    {
        $owner = $this->createCompleteUser();
        $generation = $this->completeGeneration($this->startGeneration($owner, questionCount: 1), GeminiFakeResponses::questions(1, 'Visible completed stem'));

        $this->actingAs($owner)
            ->get(route('generations.show', $generation))
            ->assertOk()
            ->assertSee('Simpan ke Question Bank')
            ->assertDontSee('Lihat di Question Bank')
            ->assertSee('Visible completed stem');

        $this->actingAs($owner)->post(route('question-sets.import', $generation));

        $this->actingAs($owner)
            ->get(route('generations.show', $generation))
            ->assertOk()
            ->assertSee('Lihat di Question Bank')
            ->assertDontSee('Simpan ke Question Bank')
            ->assertSee('Visible completed stem');
    }

    /**
     * @param  list<mixed>|array<string, mixed>|null  $questions
     */
    private function completeGeneration(AiGeneration $generation, mixed $questions): AiGeneration
    {
        $generation->forceFill([
            'generation_status' => GenerationStatus::COMPLETED,
            'result_json' => $questions,
            'completed_at' => now(),
        ])->save();

        return $generation->fresh();
    }

    /**
     * @param  list<mixed>|array<string, mixed>|null  $resultJson
     */
    private function factoryGeneration(
        User $owner,
        Material $material,
        GenerationStatus $status,
        mixed $resultJson = null,
        ?int $questionCount = null,
    ): AiGeneration {
        return AiGeneration::factory()->for($owner)->for($material)->create([
            'generation_status' => $status,
            'question_type' => QuestionType::MULTIPLE_CHOICE,
            'question_count' => $questionCount ?? (is_array($resultJson) && array_is_list($resultJson) && $resultJson !== [] ? count($resultJson) : 1),
            'result_json' => $resultJson,
            'completed_at' => $status === GenerationStatus::COMPLETED ? now() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function questionWithout(string $key): array
    {
        $question = GeminiFakeResponses::question('Stem');
        unset($question[$key]);

        return $question;
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    private function questionWithOptions(array $options): array
    {
        $question = GeminiFakeResponses::question('Stem');
        $question['options'] = $options;

        return $question;
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidAnswer(string $answer): array
    {
        $question = GeminiFakeResponses::question('Stem');
        $question['correct_answer'] = $answer;

        return $question;
    }

    /**
     * @return array<string, mixed>
     */
    private function questionWithStem(string $stem): array
    {
        $question = GeminiFakeResponses::question('Stem');
        $question['question'] = $stem;

        return $question;
    }

    /**
     * @return array<string, mixed>
     */
    private function questionWithExplanation(string $explanation): array
    {
        $question = GeminiFakeResponses::question('Stem');
        $question['explanation'] = $explanation;

        return $question;
    }

    /**
     * @return array<string, mixed>
     */
    private function duplicateOptions(): array
    {
        $question = GeminiFakeResponses::question('Stem');
        $question['options'] = [
            'A' => 'Same option',
            'B' => 'Same option',
            'C' => 'Other C',
            'D' => 'Other D',
        ];

        return $question;
    }
}
