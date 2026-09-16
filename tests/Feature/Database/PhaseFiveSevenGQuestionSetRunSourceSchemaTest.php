<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\Material;
use App\Models\QuestionSet;
use App\Models\User;
use App\Support\QuestionSets\QuestionSetSourceExclusive;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class PhaseFiveSevenGQuestionSetRunSourceSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_generation_run_id_column_is_nullable_unique_foreign_key_restrict(): void
    {
        $this->assertTrue(Schema::hasColumn('question_sets', 'generation_run_id'));
        $this->assertTrue($this->hasIndex('question_sets', ['generation_run_id'], unique: true));

        $foreignKey = collect(Schema::getForeignKeys('question_sets'))
            ->first(fn (array $key): bool => $key['columns'] === ['generation_run_id']);

        $this->assertIsArray($foreignKey);
        $this->assertSame('ai_generation_runs', $foreignKey['foreign_table']);
        $this->assertContains($foreignKey['on_delete'], ['restrict', 'no action']);
    }

    public function test_both_source_columns_null_is_allowed(): void
    {
        $user = User::factory()->create();

        QuestionSet::factory()->for($user)->create([
            'generation_id' => null,
            'generation_run_id' => null,
        ]);

        $this->assertSame(1, QuestionSet::query()->count());
    }

    public function test_app_source_exclusive_guard_rejects_both_non_null(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create();
        $run = AiGenerationRun::factory()->for($user)->create();

        $this->expectException(InvalidArgumentException::class);
        QuestionSetSourceExclusive::assert((int) $generation->generation_id, (int) $run->generation_run_id);
    }

    public function test_mysql_check_rejects_both_non_null_at_database_level(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL CHECK constraint test.');
        }

        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create();
        $run = AiGenerationRun::factory()->for($user)->create();

        $this->expectException(QueryException::class);
        DB::table('question_sets')->insert([
            'user_id' => $user->id,
            'generation_id' => $generation->generation_id,
            'generation_run_id' => $run->generation_run_id,
            'title' => 'Both sources',
            'total_question' => 0,
            'visibility' => 'private',
            'status' => 'draft',
            'review_status' => 'not_submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sqlite_allows_both_non_null_at_db_level_but_app_assert_rejects(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific application guard test.');
        }

        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create();
        $run = AiGenerationRun::factory()->for($user)->create();

        DB::table('question_sets')->insert([
            'user_id' => $user->id,
            'generation_id' => $generation->generation_id,
            'generation_run_id' => $run->generation_run_id,
            'title' => 'SQLite both sources',
            'total_question' => 0,
            'visibility' => 'private',
            'status' => 'draft',
            'review_status' => 'not_submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, QuestionSet::query()->count());

        $this->expectException(InvalidArgumentException::class);
        QuestionSetSourceExclusive::assert((int) $generation->generation_id, (int) $run->generation_run_id);
    }

    public function test_migration_down_uses_mysql_and_mariadb_specific_check_removal_syntax(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_14_100001_add_generation_run_id_to_question_sets_table.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('DROP CHECK qs_source_exclusive_chk', $source);
        $this->assertStringContainsString('DROP CONSTRAINT qs_source_exclusive_chk', $source);
        $this->assertStringContainsString("\$driver === 'mariadb'", $source);
        $this->assertStringContainsString("\$driver === 'mysql'", $source);
    }

    public function test_rollback_restores_prior_columns_without_dropping_question_sets(): void
    {
        $this->assertTrue(Schema::hasColumn('question_sets', 'generation_run_id'));

        $user = User::factory()->create();
        $setId = QuestionSet::factory()->for($user)->create([
            'generation_id' => null,
            'generation_run_id' => null,
            'title' => 'Historis sebelum run source',
        ])->question_set_id;

        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_14_100001_add_generation_run_id_to_question_sets_table.php',
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('question_sets', 'generation_run_id'));
        $this->assertTrue(Schema::hasTable('question_sets'));
        $this->assertSame('Historis sebelum run source', QuestionSet::query()->find($setId)?->title);

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_14_100001_add_generation_run_id_to_question_sets_table.php',
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('question_sets', 'generation_run_id'));
        $this->assertNull(QuestionSet::query()->find($setId)?->generation_run_id);
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
