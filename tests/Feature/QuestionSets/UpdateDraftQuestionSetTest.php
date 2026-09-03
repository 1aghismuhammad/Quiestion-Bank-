<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewStatus;
use App\Enums\RoleName;
use App\Enums\Visibility;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateDraftQuestionSetTest extends TestCase
{
    use CreatesDraftMcqQuestionSets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_owner_can_edit_a_draft_set_atomically(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 2);
        $questionIds = $set->questions->pluck('question_id')->all();
        $optionIds = QuestionOption::query()->orderBy('option_id')->pluck('option_id')->all();
        $payload = $this->updatePayload($set, [
            'title' => 'Judul disunting',
            'questions' => [
                [
                    'question_text' => 'Stem disunting 1',
                    'options' => [
                        'A' => 'Alpha satu',
                        'B' => 'Beta satu',
                        'C' => 'Gamma satu',
                        'D' => 'Delta satu',
                    ],
                    'correct_answer' => 'C',
                    'explanation' => 'Karena C benar untuk soal satu',
                ],
                [
                    'question_text' => 'Stem disunting 2',
                    'options' => [
                        'A' => 'Alpha dua',
                        'B' => 'Beta dua',
                        'C' => 'Gamma dua',
                        'D' => 'Delta dua',
                    ],
                    'correct_answer' => 'B',
                    'explanation' => 'Karena B benar untuk soal dua',
                ],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.show', $set));

        $set->refresh()->load('questions.options');
        $this->assertSame('Judul disunting', $set->title);
        $this->assertSame(QuestionSetStatus::DRAFT, $set->status);
        $this->assertSame(Visibility::PRIVATE, $set->visibility);
        $this->assertSame(ReviewStatus::NOT_SUBMITTED, $set->review_status);
        $this->assertSame(2, $set->total_question);
        $this->assertSame($questionIds, $set->questions->pluck('question_id')->all());
        $this->assertSame([1, 2], $set->questions->pluck('question_number')->all());
        $this->assertSame(2, Question::query()->count());
        $this->assertSame(8, QuestionOption::query()->count());
        $this->assertSame($optionIds, QuestionOption::query()->orderBy('option_id')->pluck('option_id')->all());

        $first = $set->questions[0];
        $this->assertSame('Stem disunting 1', $first->question_text);
        $this->assertNull($first->correct_answer);
        $this->assertSame('Karena C benar untuk soal satu', $first->explanation);
        $this->assertTrue($first->options->firstWhere('option_label', 'C')->is_correct);
        $this->assertSame(1, $first->options->where('is_correct', true)->count());
        $this->assertSame('Gamma satu', $first->options->firstWhere('option_label', 'C')->option_text);

        $this->actingAs($owner)
            ->get(route('question-sets.show', $set))
            ->assertOk()
            ->assertSee('Judul disunting')
            ->assertSee('Stem disunting 1')
            ->assertSee('Gamma satu');
    }

    public function test_invalid_question_does_not_partially_update(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 2, ['title' => 'Judul asli']);
        $original = $set->questions[1]->explanation;
        $payload = $this->updatePayload($set, [
            'title' => 'Judul baru ditolak',
            'questions' => [
                1 => ['explanation' => '   '],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors();

        $set->refresh()->load('questions');
        $this->assertSame('Judul asli', $set->title);
        $this->assertSame($original, $set->questions[1]->explanation);
        $this->assertSame(2, Question::query()->count());
        $this->assertSame(8, QuestionOption::query()->count());
    }

    public function test_duplicate_option_texts_are_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1);
        $payload = $this->updatePayload($set, [
            'questions' => [
                [
                    'options' => [
                        'A' => 'Same option',
                        'B' => 'SAME OPTION.',
                        'C' => 'Other C',
                        'D' => 'Other D',
                    ],
                ],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors();

        $this->assertSame('Option A for Stem 1', $set->questions[0]->fresh()->options->firstWhere('option_label', 'A')->option_text);
    }

    public function test_duplicate_question_stems_are_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 2);
        $payload = $this->updatePayload($set, [
            'questions' => [
                ['question_text' => 'Stem identik'],
                ['question_text' => ' STEM IDENTIK? '],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('questions.1.question_text');

        $this->assertSame('Stem 1', $set->questions[0]->fresh()->question_text);
        $this->assertSame('Stem 2', $set->questions[1]->fresh()->question_text);
    }

    public function test_invalid_correct_answer_and_extra_option_keys_are_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1);

        $invalidAnswer = $this->updatePayload($set, [
            'questions' => [
                ['correct_answer' => 'E'],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $invalidAnswer)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('questions.0.correct_answer');

        $extraKey = $this->updatePayload($set);
        $extraKey['questions'][0]['options']['E'] = 'Extra';

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $extraKey)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('questions.0.options');
    }

    public function test_foreign_and_duplicate_question_ids_are_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 2);
        $foreign = $this->draftMcqSet($stranger, 1);

        $foreignId = $this->updatePayload($set);
        $foreignId['questions'][0]['question_id'] = $foreign->questions[0]->question_id;

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $foreignId)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('questions');

        $duplicate = $this->updatePayload($set);
        $duplicate['questions'][1]['question_id'] = $set->questions[0]->question_id;

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $duplicate)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors();

        $this->assertSame('Stem 1', $set->questions[0]->fresh()->question_text);
    }

    public function test_nested_prohibited_fields_are_rejected(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1);
        $payload = $this->updatePayload($set);
        $payload['questions'][0]['question_number'] = 99;
        $payload['questions'][0]['points'] = 5;

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors();

        $this->assertSame(1, $set->questions[0]->fresh()->question_number);
    }

    public function test_stranger_and_admin_cannot_edit_foreign_set(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $admin = $this->createCompleteAdmin();
        $set = $this->draftMcqSet($owner, 1, ['title' => 'Secret edit']);
        $payload = $this->updatePayload($set, ['title' => 'Hacked']);

        $this->actingAs($stranger)
            ->patch(route('question-sets.update', $set), $payload)
            ->assertNotFound();

        $this->actingAs($stranger)
            ->get(route('question-sets.edit', $set))
            ->assertNotFound();

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->assertNotNull(Role::query()->where('role_name', RoleName::ADMIN->value)->first());

        $this->actingAs($admin)
            ->patch(route('question-sets.update', $set), $payload)
            ->assertNotFound();

        $this->assertSame('Secret edit', $set->fresh()->title);
    }

    public function test_published_set_cannot_be_edited(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1, [
            'title' => 'Sudah terbit',
            'status' => QuestionSetStatus::PUBLISHED,
        ]);
        $payload = $this->updatePayload($set, ['title' => 'Should not save']);

        $this->actingAs($owner)
            ->get(route('question-sets.edit', $set))
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch(route('question-sets.update', $set), $payload)
            ->assertForbidden();

        $this->assertSame('Sudah terbit', $set->fresh()->title);
        $this->assertSame(QuestionSetStatus::PUBLISHED, $set->fresh()->status);
    }

    public function test_edit_form_shows_current_values(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1, ['title' => 'Form judul']);

        $this->actingAs($owner)
            ->get(route('question-sets.edit', $set))
            ->assertOk()
            ->assertSee('Form judul')
            ->assertSee('Stem 1')
            ->assertSee('Simpan perubahan')
            ->assertDontSee('Hapus');
    }

    public function test_validation_errors_preserve_submitted_title(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1);
        $payload = $this->updatePayload($set, [
            'title' => 'Judul yang diketik ulang',
            'questions' => [
                ['explanation' => '   '],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set));

        $this->actingAs($owner)
            ->get(route('question-sets.edit', $set))
            ->assertOk()
            ->assertSee('Judul yang diketik ulang');
    }

    public function test_scalar_questions_is_rejected_without_mutation(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1, ['title' => 'Judul asli']);
        $originalText = $set->questions[0]->question_text;
        $originalExplanation = $set->questions[0]->explanation;

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), [
                'title' => 'Judul ditolak',
                'questions' => 'bukan-list',
            ])
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('questions');

        $set->refresh()->load('questions.options');
        $this->assertSame('Judul asli', $set->title);
        $this->assertSame($originalText, $set->questions[0]->question_text);
        $this->assertSame($originalExplanation, $set->questions[0]->explanation);
        $this->assertSame(1, Question::query()->count());
        $this->assertSame(4, QuestionOption::query()->count());
    }

    public function test_sparse_question_indexes_are_rejected_without_mutation(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 2, ['title' => 'Judul asli']);
        $original = $set->questions->pluck('question_text')->all();
        $payload = $this->updatePayload($set, ['title' => 'Judul ditolak']);
        $payload['questions'] = [
            0 => $payload['questions'][0],
            2 => $payload['questions'][1],
        ];

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('questions');

        $set->refresh()->load('questions');
        $this->assertSame('Judul asli', $set->title);
        $this->assertSame($original, $set->questions->pluck('question_text')->all());
        $this->assertSame(2, Question::query()->count());
        $this->assertSame(8, QuestionOption::query()->count());
    }

    public function test_true_false_question_cannot_be_edited_even_with_valid_mcq_options(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1, ['title' => 'Judul asli']);
        $question = $set->questions[0];
        $question->forceFill(['question_type' => QuestionType::TRUE_FALSE])->save();
        $this->assertSame(4, $question->options()->count());
        $this->assertSame(1, $question->options()->where('is_correct', true)->count());

        $payload = $this->updatePayload($set->fresh()->load('questions.options'), [
            'title' => 'Judul ditolak',
            'questions' => [
                ['question_text' => 'Stem ditolak'],
            ],
        ]);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set))
            ->assertSessionHasErrors('question_type');

        $set->refresh()->load('questions.options');
        $this->assertSame('Judul asli', $set->title);
        $this->assertSame('Stem 1', $set->questions[0]->question_text);
        $this->assertSame(QuestionType::TRUE_FALSE, $set->questions[0]->question_type);
        $this->assertNull($set->questions[0]->correct_answer);
        $this->assertSame(['A', 'B', 'C', 'D'], $set->questions[0]->options->pluck('option_label')->all());
        $this->assertSame(1, Question::query()->count());
        $this->assertSame(4, QuestionOption::query()->count());
    }
}
