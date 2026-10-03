<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Enums\QuestionSetStatus;
use App\Models\QuestionSet;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionBankPresentationTest extends TestCase
{
    use CreatesDraftMcqQuestionSets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_index_keeps_four_columns_and_no_unplanned_controls(): void
    {
        $owner = $this->createCompleteUser();
        QuestionSet::factory()->for($owner)->create(['title' => 'Bank presentasi', 'total_question' => 4]);

        $this->actingAs($owner)
            ->get(route('question-sets.index'))
            ->assertOk()
            ->assertSee('question-set-index-page', false)
            ->assertSeeInOrder(['Judul', 'Status', 'Jumlah soal', 'Dibuat'])
            ->assertSee('Bank soal')
            ->assertSee('Bank presentasi')
            ->assertSee('aria-label="Buka Bank presentasi"', false)
            ->assertDontSee('type="search"', false)
            ->assertDontSee('type="checkbox"', false)
            ->assertDontSee('Buat Bank Soal')
            ->assertDontSee('Hapus')
            ->assertDontSee('Arsipkan')
            ->assertDontSee('Duplikat');
    }

    public function test_show_lists_every_question_without_preview_language(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 6, ['title' => 'Detail lengkap']);

        $response = $this->actingAs($owner)
            ->get(route('question-sets.show', $set))
            ->assertOk()
            ->assertSee('Kembali ke bank soal')
            ->assertSee('Jumlah soal')
            ->assertSee('Dibuat')
            ->assertSee('Jawaban benar')
            ->assertSee('Penjelasan')
            ->assertDontSee('pratinjau', false)
            ->assertDontSee('Bloom')
            ->assertDontSee('Bobot');

        foreach (range(1, 6) as $number) {
            $response->assertSee('Soal '.$number)->assertSee('Stem '.$number);
        }
    }

    public function test_show_renders_true_false_and_essay_bodies_for_mixed_set(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSet($owner);

        $this->actingAs($owner)
            ->get(route('question-sets.show', $set))
            ->assertOk()
            ->assertSee('Mixed MCQ')
            ->assertSee('Mixed TF')
            ->assertSee('Mixed Essay')
            ->assertSee('Benar/Salah')
            ->assertSee('Contoh jawaban')
            ->assertSee('Rubrik')
            ->assertSee('Terbitkan')
            ->assertSee('Edit');
    }

    public function test_show_non_draft_non_published_status_has_no_actions(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1, ['status' => QuestionSetStatus::ARCHIVED]);

        $this->actingAs($owner)
            ->get(route('question-sets.show', $set))
            ->assertOk()
            ->assertDontSee('Terbitkan')
            ->assertDontSee('Unduh DOCX')
            ->assertDontSee(route('question-sets.edit', $set), false);
    }

    public function test_edit_keeps_fixed_structure_and_single_save_action(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSet($owner);

        $response = $this->actingAs($owner)
            ->get(route('question-sets.edit', $set))
            ->assertOk()
            ->assertSee('question-set-edit-page', false)
            ->assertSee('name="title"', false)
            ->assertSee('Kembali ke detail')
            ->assertSee('Opsi A')
            ->assertSee('Opsi D')
            ->assertSee('value="Benar"', false)
            ->assertSee('value="Salah"', false)
            ->assertSee('Contoh jawaban')
            ->assertSee('Rubrik')
            ->assertDontSee('Tambah soal')
            ->assertDontSee('Hapus soal')
            ->assertDontSee('Simpan perubahan')
            ->assertDontSee('Simpan draf')
            ->assertDontSee('Terbitkan');

        foreach ($set->questions as $index => $question) {
            $response->assertSee('name="questions['.$index.'][question_id]"', false);
        }

        $this->assertSame(1, preg_match('/<form class="question-set-edit-form".*?<\/form>/s', $response->getContent(), $form));
        $this->assertSame(1, substr_count($form[0], 'type="submit"'));
        $this->assertSame(1, substr_count($form[0], '>Simpan<'));
    }

    public function test_edit_marks_invalid_fields_with_inline_errors(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSet($owner);
        $payload = $this->typedUpdatePayload($set, ['title' => '']);

        $this->actingAs($owner)
            ->from(route('question-sets.edit', $set))
            ->patch(route('question-sets.update', $set), $payload)
            ->assertRedirect(route('question-sets.edit', $set));

        $this->actingAs($owner)
            ->get(route('question-sets.edit', $set))
            ->assertOk()
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="title-error"', false);
    }
}
