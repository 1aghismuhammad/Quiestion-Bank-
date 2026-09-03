<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Enums\GenerationStatus;
use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewStatus;
use App\Enums\RoleName;
use App\Enums\Visibility;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\Role;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Generations\StartsQuestionGenerations;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\TestCase;

class PublishQuestionSetTest extends TestCase
{
    use CreatesDraftMcqQuestionSets;
    use RefreshDatabase;
    use StartsQuestionGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_owner_can_publish_a_valid_draft(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 2);
        $questionCount = Question::query()->count();
        $optionCount = QuestionOption::query()->count();

        $this->actingAs($owner)
            ->from(route('question-sets.show', $set))
            ->post(route('question-sets.publish', $set))
            ->assertRedirect(route('question-sets.show', $set));

        $set->refresh();
        $this->assertSame(QuestionSetStatus::PUBLISHED, $set->status);
        $this->assertSame(Visibility::PRIVATE, $set->visibility);
        $this->assertSame(ReviewStatus::NOT_SUBMITTED, $set->review_status);
        $this->assertSame($questionCount, Question::query()->count());
        $this->assertSame($optionCount, QuestionOption::query()->count());
        $this->assertSame(1, QuestionSet::query()->count());

        $this->actingAs($owner)
            ->get(route('question-sets.show', $set))
            ->assertOk()
            ->assertSee('published')
            ->assertDontSee('>Edit<', false)
            ->assertDontSee('>Terbitkan<', false)
            ->assertDontSee('Hapus');
    }

    public function test_repeat_publish_is_idempotent(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1);

        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();
        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect(route('question-sets.show', $set));

        $this->assertSame(1, QuestionSet::query()->where('status', QuestionSetStatus::PUBLISHED)->count());
        $this->assertSame(1, Question::query()->count());
        $this->assertSame(4, QuestionOption::query()->count());
        $this->assertSame(QuestionSetStatus::PUBLISHED, $set->fresh()->status);
        $this->assertSame(Visibility::PRIVATE, $set->fresh()->visibility);
        $this->assertSame(ReviewStatus::NOT_SUBMITTED, $set->fresh()->review_status);
    }

    public function test_publish_rejects_total_question_mismatch(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 2);
        $set->forceFill(['total_question' => 3])->save();

        $this->actingAs($owner)
            ->from(route('question-sets.show', $set))
            ->post(route('question-sets.publish', $set))
            ->assertRedirect(route('question-sets.show', $set))
            ->assertSessionHasErrors('total_question');

        $this->assertSame(QuestionSetStatus::DRAFT, $set->fresh()->status);
    }

    public function test_publish_rejects_malformed_option_structure(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1);
        QuestionOption::query()->where('option_label', 'D')->delete();

        $this->actingAs($owner)
            ->from(route('question-sets.show', $set))
            ->post(route('question-sets.publish', $set))
            ->assertRedirect(route('question-sets.show', $set))
            ->assertSessionHasErrors();

        $this->assertSame(QuestionSetStatus::DRAFT, $set->fresh()->status);
    }

    public function test_publish_rejects_invalid_numbering_and_empty_set(): void
    {
        $owner = $this->createCompleteUser();
        $badNumbers = $this->draftMcqSet($owner, 2);
        $badNumbers->questions[1]->forceFill(['question_number' => 4])->save();

        $this->actingAs($owner)
            ->from(route('question-sets.show', $badNumbers))
            ->post(route('question-sets.publish', $badNumbers))
            ->assertSessionHasErrors('questions');

        $this->assertSame(QuestionSetStatus::DRAFT, $badNumbers->fresh()->status);

        $empty = QuestionSet::factory()->for($owner)->create([
            'status' => QuestionSetStatus::DRAFT,
            'total_question' => 0,
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.show', $empty))
            ->post(route('question-sets.publish', $empty))
            ->assertSessionHasErrors('questions');

        $this->assertSame(QuestionSetStatus::DRAFT, $empty->fresh()->status);
    }

    public function test_publish_rejects_non_mcq_and_non_draft_lifecycle(): void
    {
        $owner = $this->createCompleteUser();
        $nonMcq = $this->draftMcqSet($owner, 1);
        $nonMcq->questions[0]->forceFill(['question_type' => QuestionType::TRUE_FALSE])->save();

        $this->actingAs($owner)
            ->from(route('question-sets.show', $nonMcq))
            ->post(route('question-sets.publish', $nonMcq))
            ->assertSessionHasErrors('question_type');

        $this->assertSame(QuestionSetStatus::DRAFT, $nonMcq->fresh()->status);

        foreach ([QuestionSetStatus::GENERATING, QuestionSetStatus::REVIEW, QuestionSetStatus::ARCHIVED] as $status) {
            $set = $this->draftMcqSet($owner, 1, ['status' => $status]);

            $this->actingAs($owner)
                ->from(route('question-sets.show', $set))
                ->post(route('question-sets.publish', $set))
                ->assertRedirect(route('question-sets.show', $set))
                ->assertSessionHasErrors('status');

            $this->assertSame($status, $set->fresh()->status);
        }
    }

    public function test_stranger_and_admin_cannot_publish_foreign_set(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $admin = $this->createCompleteAdmin();
        $set = $this->draftMcqSet($owner, 1);

        $this->actingAs($stranger)
            ->post(route('question-sets.publish', $set))
            ->assertNotFound();

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->assertNotNull(Role::query()->where('role_name', RoleName::ADMIN->value)->first());

        $this->actingAs($admin)
            ->post(route('question-sets.publish', $set))
            ->assertNotFound();

        $this->assertSame(QuestionSetStatus::DRAFT, $set->fresh()->status);
    }

    public function test_edit_then_publish_uses_edited_content(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1);
        $payload = $this->updatePayload($set, [
            'title' => 'Siap terbit',
            'questions' => [
                ['question_text' => 'Stem siap terbit'],
            ],
        ]);

        $this->actingAs($owner)->patch(route('question-sets.update', $set), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();

        $set->refresh()->load('questions');
        $this->assertSame(QuestionSetStatus::PUBLISHED, $set->status);
        $this->assertSame('Siap terbit', $set->title);
        $this->assertSame('Stem siap terbit', $set->questions[0]->question_text);

        $this->actingAs($owner)
            ->patch(route('question-sets.update', $set), $this->updatePayload($set, ['title' => 'Terlalu telat']))
            ->assertForbidden();

        $this->assertSame('Siap terbit', $set->fresh()->title);
    }

    public function test_publish_does_not_change_generation_or_usage(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create(['title' => 'Materi publikasi']);
        $generation = $this->startGeneration($owner, $material, questionCount: 1);
        $generation->forceFill([
            'generation_status' => GenerationStatus::COMPLETED,
            'result_json' => GeminiFakeResponses::questions(1, 'Publikasi'),
            'completed_at' => now(),
        ])->save();
        $generation = $generation->fresh();
        $resultJson = $generation->result_json;
        $usageStatus = $generation->usageLog->status;

        $this->actingAs($owner)->post(route('question-sets.import', $generation))->assertRedirect();
        $set = QuestionSet::query()->firstOrFail();

        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();

        $generation->refresh();
        $this->assertSame($resultJson, $generation->result_json);
        $this->assertSame(GenerationStatus::COMPLETED, $generation->generation_status);
        $this->assertSame($usageStatus, $generation->usageLog->fresh()->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_id', $generation->generation_id)->count());
        $this->assertSame(1, QuestionSet::query()->count());
    }

    public function test_draft_show_has_edit_and_publish_controls(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1, ['title' => 'Kontrol draf']);

        $this->actingAs($owner)
            ->get(route('question-sets.show', $set))
            ->assertOk()
            ->assertSee('Kontrol draf')
            ->assertSee('Edit')
            ->assertSee('Terbitkan')
            ->assertDontSee('Hapus')
            ->assertDontSee('execution_token');
    }
}
