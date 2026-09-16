<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Models\QuestionOption;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TypedPublishQuestionSetTest extends TestCase
{
    use CreatesDraftMcqQuestionSets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_owner_can_publish_valid_true_false_and_essay_drafts(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSet($owner);

        $this->actingAs($owner)
            ->from(route('question-sets.show', $set))
            ->post(route('question-sets.publish', $set))
            ->assertRedirect(route('question-sets.show', $set));

        $set->refresh();
        $this->assertSame(QuestionSetStatus::PUBLISHED, $set->status);
        $this->assertSame(3, $set->questions()->count());
    }

    public function test_publish_rejects_malformed_true_false_structure(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftTrueFalseSet($owner, 1);
        QuestionOption::query()->where('option_label', 'FALSE')->delete();

        $this->actingAs($owner)
            ->from(route('question-sets.show', $set))
            ->post(route('question-sets.publish', $set))
            ->assertRedirect(route('question-sets.show', $set))
            ->assertSessionHasErrors();

        $this->assertSame(QuestionSetStatus::DRAFT, $set->fresh()->status);
    }

    public function test_publish_rejects_malformed_essay_without_rubric(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftEssaySet($owner, 1);
        $set->questions[0]->forceFill(['rubric' => '   '])->save();

        $this->actingAs($owner)
            ->from(route('question-sets.show', $set))
            ->post(route('question-sets.publish', $set))
            ->assertRedirect(route('question-sets.show', $set))
            ->assertSessionHasErrors();

        $this->assertSame(QuestionSetStatus::DRAFT, $set->fresh()->status);
    }

    public function test_repeat_publish_is_idempotent_for_typed_set(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSet($owner);

        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();
        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();

        $this->assertSame(3, $set->fresh()->questions()->count());
        $this->assertSame(QuestionSetStatus::PUBLISHED, $set->fresh()->status);
    }

    public function test_publish_failure_leaves_draft_status(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftEssaySet($owner, 1);
        $set->questions[0]->forceFill(['correct_answer' => ''])->save();

        $this->actingAs($owner)
            ->from(route('question-sets.show', $set))
            ->post(route('question-sets.publish', $set))
            ->assertRedirect(route('question-sets.show', $set))
            ->assertSessionHasErrors();

        $this->assertSame(QuestionSetStatus::DRAFT, $set->fresh()->status);
    }

    public function test_edit_then_publish_uses_typed_content(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftTrueFalseSet($owner, 1);
        $payload = $this->typedUpdatePayload($set, [
            'title' => 'Siap terbit TF',
            'questions' => [
                [
                    'question_text' => 'Pernyataan siap terbit',
                    'correct_answer' => 'Benar',
                    'explanation' => 'Karena pernyataan benar',
                ],
            ],
        ]);

        $this->actingAs($owner)->patch(route('question-sets.update', $set), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();

        $set->refresh()->load('questions.options');
        $this->assertSame(QuestionSetStatus::PUBLISHED, $set->status);
        $this->assertSame('Siap terbit TF', $set->title);
        $this->assertSame('Pernyataan siap terbit', $set->questions[0]->question_text);
        $this->assertSame(QuestionType::TRUE_FALSE, $set->questions[0]->question_type);
    }
}
