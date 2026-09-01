<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\QuestionSetStatus;
use App\Enums\ReviewStatus;
use App\Enums\Visibility;
use App\Models\AiGeneration;
use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseFiveQuestionBankSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_question_bank_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('question_sets'));
        $this->assertTrue(Schema::hasTable('questions'));
        $this->assertTrue(Schema::hasTable('question_options'));
    }

    public function test_question_sets_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('question_sets', [
            'question_set_id',
            'user_id',
            'generation_id',
            'title',
            'description',
            'subject',
            'grade_level',
            'total_question',
            'visibility',
            'status',
            'review_status',
            'reviewed_by',
            'reviewed_at',
            'review_notes',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
        $this->assertFalse(Schema::hasColumn('question_sets', 'material_id'));
        $this->assertFalse(Schema::hasColumn('question_sets', 'output_language'));
    }

    public function test_questions_and_options_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('questions', [
            'question_id',
            'question_set_id',
            'question_number',
            'question_text',
            'question_type',
            'difficulty_level',
            'correct_answer',
            'explanation',
            'rubric',
            'points',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('question_options', [
            'option_id',
            'question_id',
            'option_label',
            'option_text',
            'is_correct',
            'sort_order',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_generation_id_is_nullable_and_unique(): void
    {
        $this->assertTrue($this->hasIndex('question_sets', ['generation_id'], unique: true));

        $user = User::factory()->create();
        QuestionSet::factory()->for($user)->create(['generation_id' => null]);
        QuestionSet::factory()->for($user)->create(['generation_id' => null]);
        $this->assertSame(2, QuestionSet::query()->count());

        $material = Material::factory()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create();
        QuestionSet::factory()->for($user)->create(['generation_id' => $generation->generation_id]);

        $this->expectException(QueryException::class);
        QuestionSet::factory()->for($user)->create(['generation_id' => $generation->generation_id]);
    }

    public function test_question_number_is_unique_per_set(): void
    {
        $this->assertTrue($this->hasIndex('questions', ['question_set_id', 'question_number'], unique: true));

        $set = QuestionSet::factory()->create();
        Question::factory()->for($set, 'questionSet')->create(['question_number' => 1]);

        $this->expectException(QueryException::class);
        Question::factory()->for($set, 'questionSet')->create(['question_number' => 1]);
    }

    public function test_option_label_and_sort_order_are_unique_per_question(): void
    {
        $this->assertTrue($this->hasIndex('question_options', ['question_id', 'option_label'], unique: true));
        $this->assertTrue($this->hasIndex('question_options', ['question_id', 'sort_order'], unique: true));

        $question = Question::factory()->create();
        QuestionOption::factory()->for($question)->create([
            'option_label' => 'A',
            'sort_order' => 1,
        ]);

        $this->expectException(QueryException::class);
        QuestionOption::factory()->for($question)->create([
            'option_label' => 'A',
            'sort_order' => 2,
        ]);
    }

    public function test_relations_and_enum_casts(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create();
        $set = QuestionSet::factory()->for($user)->create([
            'generation_id' => $generation->generation_id,
            'status' => QuestionSetStatus::DRAFT,
            'review_status' => ReviewStatus::NOT_SUBMITTED,
            'visibility' => Visibility::PRIVATE,
        ]);
        $question = Question::factory()->for($set, 'questionSet')->create(['question_number' => 1]);
        $option = QuestionOption::factory()->for($question)->create([
            'option_label' => 'A',
            'sort_order' => 1,
            'is_correct' => true,
        ]);

        $this->assertTrue($user->fresh()->questionSets->first()->is($set));
        $this->assertTrue($generation->fresh()->questionSet->is($set));
        $this->assertTrue($set->fresh()->generation->is($generation));
        $this->assertTrue($set->fresh()->questions->first()->is($question));
        $this->assertTrue($question->fresh()->options->first()->is($option));
        $this->assertSame(QuestionSetStatus::DRAFT, $set->fresh()->status);
        $this->assertSame(ReviewStatus::NOT_SUBMITTED, $set->fresh()->review_status);
        $this->assertSame(Visibility::PRIVATE, $set->fresh()->visibility);
        $this->assertTrue($option->fresh()->is_correct);
    }

    public function test_foreign_keys_use_expected_delete_behavior(): void
    {
        $userFk = $this->foreignKey('question_sets', 'user_id');
        $this->assertSame('users', $userFk['foreign_table']);
        $this->assertContains($userFk['on_delete'], ['restrict', 'no action']);

        $generationFk = $this->foreignKey('question_sets', 'generation_id');
        $this->assertSame('ai_generations', $generationFk['foreign_table']);
        $this->assertContains($generationFk['on_delete'], ['restrict', 'no action']);

        $questionFk = $this->foreignKey('questions', 'question_set_id');
        $this->assertContains($questionFk['on_delete'], ['cascade']);

        $optionFk = $this->foreignKey('question_options', 'question_id');
        $this->assertContains($optionFk['on_delete'], ['cascade']);
    }

    /**
     * @return array{foreign_table: string, foreign_columns: list<string>, on_delete: string, name?: string}
     */
    private function foreignKey(string $table, string $column): array
    {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $key): bool => $key['columns'] === [$column]);

        $this->assertIsArray($foreignKey, "Expected foreign key on {$table}.{$column}");

        return $foreignKey;
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndex(string $table, array $columns, bool $unique): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => $index['columns'] === $columns && $index['unique'] === $unique,
        );
    }
}
