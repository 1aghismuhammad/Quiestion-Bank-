<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Enums\RoleName;
use App\Models\Question;
use App\Models\QuestionOption;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TypedDraftQuestionSetTest extends TestCase
{
    use CreatesDraftMcqQuestionSets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_owner_can_edit_true_false_and_essay_drafts_atomically(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSet($owner);
        $questionIds = $set->questions->pluck('question_id')->all();
        $payload = $this->typedUpdatePayload($set, [
            'title' => 'Judul campuran',
            'questions' => [
                [
                    'question_text' => 'MCQ disunting',
                    'options' => [
                        'A' => 'Alpha',
                        'B' => 'Beta',
                        'C' => 'Gamma',
                        'D' => 'Delta',
                    ],
                    'correct_answer' => 'B',
                    'explanation' => 'Karena B benar untuk MCQ',
                ],
                [
                    'question_text' => 'TF disunting',
                    'correct_answer' => 'Salah',
                    'explanation' => 'Karena pernyataan salah',
                ],
                [
                    'question_text' => 'Essay disunting',
                    'model_answer' => 'Jawaban model esai',
                    'rubric' => 'Rubrik esai disunting',
                    'explanation' => 'Karena esai perlu penjelasan',
                ],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.show', $set));

        $set->refresh()->load('questions.options');
        $this->assertSame('Judul campuran', $set->title);
        $this->assertSame($questionIds, $set->questions->pluck('question_id')->all());
        $this->assertSame('MCQ disunting', $set->questions[0]->question_text);
        $this->assertTrue($set->questions[0]->options->firstWhere('option_label', 'B')->is_correct);
        $this->assertSame('TF disunting', $set->questions[1]->question_text);
        $this->assertFalse($set->questions[1]->options->firstWhere('option_label', 'TRUE')->is_correct);
        $this->assertSame('Essay disunting', $set->questions[2]->question_text);
        $this->assertSame('Jawaban model esai', $set->questions[2]->correct_answer);
        $this->assertSame('Rubrik esai disunting', $set->questions[2]->rubric);
        $this->assertSame(0, $set->questions[2]->options()->count());
    }

    public function test_invalid_essay_row_does_not_partially_update(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftEssaySet($owner, 1, ['title' => 'Judul asli']);
        $payload = $this->typedUpdatePayload($set, [
            'title' => 'Judul ditolak',
            'questions' => [
                ['rubric' => '   '],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors();

        $set->refresh();
        $this->assertSame('Judul asli', $set->title);
        $this->assertSame('Essay stem 1', $set->questions[0]->question_text);
    }

    public function test_foreign_and_duplicate_question_ids_are_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $set = $this->draftTrueFalseSet($owner, 2);
        $foreign = $this->draftTrueFalseSet($stranger, 1);

        $foreignId = $this->typedUpdatePayload($set);
        $foreignId['questions'][0]['question_id'] = $foreign->questions[0]->question_id;

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $foreignId)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('questions.0.question_id');

        $duplicate = $this->typedUpdatePayload($set);
        $duplicate['questions'][1]['question_id'] = $set->questions[0]->question_id;

        $this->actingAs($owner)
            ->patch(route('question-sets.update', $set), $duplicate)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors();
    }

    public function test_generation_run_id_is_prohibited_in_payload(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftTrueFalseSet($owner, 1);
        $payload = $this->typedUpdatePayload($set, ['generation_run_id' => 99]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('generation_run_id');

        $nested = $this->typedUpdatePayload($set);
        $nested['questions'][0]['generation_run_id'] = 99;

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $nested)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('questions.0.generation_run_id');
    }

    public function test_stranger_and_admin_cannot_edit_foreign_set(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $admin = $this->createCompleteAdmin();
        $set = $this->draftEssaySet($owner, 1, ['title' => 'Secret typed edit']);
        $payload = $this->typedUpdatePayload($set, ['title' => 'Hacked']);

        $this->actingAs($stranger)
            ->patch(route('question-sets.update', $set), $payload)
            ->assertNotFound();

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->actingAs($admin)
            ->patch(route('question-sets.update', $set), $payload)
            ->assertNotFound();

        $this->assertSame('Secret typed edit', $set->fresh()->title);
    }

    public function test_published_set_cannot_be_edited(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSet($owner);
        $set->forceFill(['status' => QuestionSetStatus::PUBLISHED])->save();
        $payload = $this->typedUpdatePayload($set, ['title' => 'Should not save']);

        $this->actingAs($owner)
            ->get(route('question-sets.edit', $set))
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch(route('question-sets.update', $set), $payload)
            ->assertForbidden();
    }

    public function test_malformed_true_false_structure_is_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1, ['title' => 'Judul asli']);
        $question = $set->questions[0];
        $question->forceFill(['question_type' => QuestionType::TRUE_FALSE])->save();
        $this->assertSame(4, $question->options()->count());

        $payload = $this->typedUpdatePayload($set->fresh()->load('questions.options'), [
            'title' => 'Judul ditolak',
            'questions' => [
                ['question_text' => 'Stem ditolak'],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors();

        $set->refresh()->load('questions');
        $this->assertSame('Judul asli', $set->title);
        $this->assertSame('Stem 1', $set->questions[0]->question_text);
        $this->assertSame(1, Question::query()->count());
        $this->assertSame(4, QuestionOption::query()->count());
    }
}
