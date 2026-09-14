<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintSource;
use App\Models\Material;
use App\Models\QuestionBlueprintSeries;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseFiveSevenBlueprintAndRunSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprint_and_run_tables_exist(): void
    {
        foreach ([
            'question_blueprint_series',
            'question_blueprints',
            'question_blueprint_rows',
            'question_blueprint_row_contexts',
            'question_blueprint_attempts',
            'question_blueprint_fill_events',
            'ai_generation_runs',
            'ai_generation_run_items',
            'ai_generation_run_item_spans',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' should exist');
        }
    }

    public function test_run_usage_unique_and_generation_child_unique_exist(): void
    {
        $this->assertTrue($this->hasIndex('ai_usage_logs', ['generation_run_id'], unique: true));
        $this->assertTrue($this->hasIndex('ai_generations', ['generation_run_id', 'child_index'], unique: true));
        $this->assertTrue($this->hasIndex('ai_generation_runs', ['user_id', 'idempotency_key'], unique: true));
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndex(string $table, array $columns, bool $unique): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['unique'] ?? false) !== $unique) {
                continue;
            }

            if (array_values($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    public function test_blueprint_mode_defaults_to_simple_and_rollback_restores_prior_columns(): void
    {
        $this->seed(PlanSeeder::class);
        $this->assertTrue(Schema::hasColumn('question_blueprints', 'mode'));
        $this->assertTrue(Schema::hasColumn('question_blueprints', 'ai_fill_requested_total'));

        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $series = QuestionBlueprintSeries::factory()->forOwner($user, $material)->create();
        $now = now();

        $id = DB::table('question_blueprints')->insertGetId([
            'blueprint_series_id' => $series->blueprint_series_id,
            'user_id' => $user->id,
            'material_id' => $material->material_id,
            'version' => 1,
            'lifecycle_status' => BlueprintLifecycleStatus::Draft->value,
            'source' => BlueprintSource::Manual->value,
            'ai_fill_status' => BlueprintAiFillStatus::None->value,
            'assessment_type' => AssessmentType::FORMATIVE->value,
            'title' => 'Historis tanpa mode',
            'material_content_hash' => hash('sha256', 'fixture'),
            'extractor_implementation' => 'test-extractor',
            'created_at' => $now,
            'updated_at' => $now,
        ], 'blueprint_id');

        $row = DB::table('question_blueprints')->where('blueprint_id', $id)->first();
        $this->assertSame('simple', $row->mode);
        $this->assertNull($row->ai_fill_requested_total);

        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_11_100001_add_mode_and_ai_fill_target_to_question_blueprints_table.php',
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('question_blueprints', 'mode'));
        $this->assertFalse(Schema::hasColumn('question_blueprints', 'ai_fill_requested_total'));
        $this->assertTrue(Schema::hasTable('question_blueprints'));
        $this->assertTrue(Schema::hasTable('ai_generation_runs'));

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_11_100001_add_mode_and_ai_fill_target_to_question_blueprints_table.php',
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('question_blueprints', 'mode'));
        $this->assertSame('simple', DB::table('question_blueprints')->where('blueprint_id', $id)->value('mode'));
    }

    public function test_ai_fill_requested_type_counts_is_nullable_and_rolls_back_independently(): void
    {
        $this->seed(PlanSeeder::class);
        $this->assertTrue(Schema::hasColumn('question_blueprints', 'ai_fill_requested_type_counts'));
        $this->assertTrue(Schema::hasColumn('question_blueprints', 'mode'));

        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $series = QuestionBlueprintSeries::factory()->forOwner($user, $material)->create();
        $now = now();

        $id = DB::table('question_blueprints')->insertGetId([
            'blueprint_series_id' => $series->blueprint_series_id,
            'user_id' => $user->id,
            'material_id' => $material->material_id,
            'version' => 1,
            'lifecycle_status' => BlueprintLifecycleStatus::Draft->value,
            'source' => BlueprintSource::Manual->value,
            'ai_fill_status' => BlueprintAiFillStatus::None->value,
            'assessment_type' => AssessmentType::FORMATIVE->value,
            'title' => 'Historis tanpa komposisi',
            'material_content_hash' => hash('sha256', 'fixture'),
            'extractor_implementation' => 'test-extractor',
            'created_at' => $now,
            'updated_at' => $now,
        ], 'blueprint_id');

        $row = DB::table('question_blueprints')->where('blueprint_id', $id)->first();
        $this->assertNull($row->ai_fill_requested_type_counts);

        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_13_100001_add_ai_fill_requested_type_counts_to_question_blueprints_table.php',
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('question_blueprints', 'ai_fill_requested_type_counts'));
        $this->assertTrue(Schema::hasColumn('question_blueprints', 'mode'));
        $this->assertTrue(Schema::hasColumn('question_blueprints', 'ai_fill_requested_total'));

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_13_100001_add_ai_fill_requested_type_counts_to_question_blueprints_table.php',
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('question_blueprints', 'ai_fill_requested_type_counts'));
        $this->assertNull(DB::table('question_blueprints')->where('blueprint_id', $id)->value('ai_fill_requested_type_counts'));
    }
}
