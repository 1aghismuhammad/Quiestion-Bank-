<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationStatus;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseFourGenerationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_generation_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('ai_generations'));
        $this->assertTrue(Schema::hasTable('ai_usage_logs'));
    }

    public function test_ai_generations_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('ai_generations', [
            'generation_id',
            'user_id',
            'material_id',
            'assessment_type',
            'difficulty_level',
            'question_type',
            'question_count',
            'generation_status',
            'error_message',
            'attempt_number',
            'parent_generation_id',
            'queued_at',
            'started_at',
            'completed_at',
            'created_at',
            'updated_at',
            'output_language',
            'execution_token',
            'result_json',
            'provider_name',
            'model_name',
            'input_tokens',
            'output_tokens',
            'failed_at',
            'error_code',
        ]));
    }

    public function test_ai_generations_omits_speculative_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('ai_generations', 'topic_id'));
        $this->assertFalse(Schema::hasColumn('ai_generations', 'prompt_version_id'));
        $this->assertFalse(Schema::hasColumn('ai_generations', 'prompt_version'));
        $this->assertFalse(Schema::hasColumn('ai_generations', 'raw_response'));
        $this->assertFalse(Schema::hasColumn('ai_generations', 'parsed_output'));
    }

    public function test_ai_usage_logs_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('ai_usage_logs', [
            'usage_id',
            'user_id',
            'plan_id',
            'subscription_id',
            'generation_id',
            'status',
            'window_start',
            'window_end',
            'reserved_at',
            'finalized_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_ai_usage_logs_omits_speculative_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('ai_usage_logs', 'credit_used'));
        $this->assertFalse(Schema::hasColumn('ai_usage_logs', 'usage_action'));
        $this->assertFalse(Schema::hasColumn('ai_usage_logs', 'action_type'));
        $this->assertFalse(Schema::hasColumn('ai_usage_logs', 'reservation_expires_at'));
        $this->assertFalse(Schema::hasColumn('ai_usage_logs', 'token_used'));
        $this->assertFalse(Schema::hasColumn('ai_usage_logs', 'estimated_cost'));
    }

    public function test_generation_user_and_material_foreign_keys_restrict_delete(): void
    {
        $userFk = $this->foreignKey('ai_generations', 'user_id');
        $this->assertSame('users', $userFk['foreign_table']);
        $this->assertContains($userFk['on_delete'], ['restrict', 'no action']);

        $materialFk = $this->foreignKey('ai_generations', 'material_id');
        $this->assertSame('materials', $materialFk['foreign_table']);
        $this->assertSame(['material_id'], $materialFk['foreign_columns']);
        $this->assertContains($materialFk['on_delete'], ['restrict', 'no action']);
    }

    public function test_usage_foreign_keys_restrict_delete(): void
    {
        foreach (['user_id', 'plan_id', 'subscription_id', 'generation_id'] as $column) {
            $foreignKey = $this->foreignKey('ai_usage_logs', $column);
            $this->assertContains($foreignKey['on_delete'], ['restrict', 'no action']);
        }

        $this->assertSame('ai_generations', $this->foreignKey('ai_usage_logs', 'generation_id')['foreign_table']);
        $this->assertSame('plans', $this->foreignKey('ai_usage_logs', 'plan_id')['foreign_table']);
        $this->assertSame('subscriptions', $this->foreignKey('ai_usage_logs', 'subscription_id')['foreign_table']);
    }

    public function test_generation_id_on_usage_is_unique(): void
    {
        $this->assertTrue($this->hasIndex('ai_usage_logs', ['generation_id'], unique: true));

        $unique = $this->index('ai_usage_logs', ['generation_id'], unique: true);
        $this->assertSame('ai_usage_generation_unique', $unique['name']);
    }

    public function test_approved_indexes_exist_and_fit_mysql_identifier_limit(): void
    {
        $this->assertTrue($this->hasIndex('ai_generations', ['user_id', 'created_at'], unique: false));
        $this->assertTrue($this->hasIndex('ai_generations', ['material_id'], unique: false));
        $this->assertTrue($this->hasIndex('ai_generations', ['generation_status'], unique: false));
        $this->assertTrue($this->hasIndex('ai_generations', ['parent_generation_id'], unique: false));
        $this->assertTrue($this->hasIndex('ai_usage_logs', ['user_id', 'status'], unique: false));
        $this->assertTrue($this->hasIndex('ai_usage_logs', ['user_id', 'plan_id', 'status'], unique: false));
        $this->assertTrue($this->hasIndex('ai_usage_logs', ['user_id', 'subscription_id', 'window_start', 'status'], unique: false));

        foreach (['ai_generations', 'ai_usage_logs'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen($index['name']),
                    "Index {$index['name']} on {$table} exceeds MySQL's 64-character identifier limit.",
                );
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (! isset($foreignKey['name']) || $foreignKey['name'] === '') {
                    continue;
                }

                $this->assertLessThanOrEqual(
                    64,
                    strlen($foreignKey['name']),
                    "Foreign key {$foreignKey['name']} on {$table} exceeds MySQL's 64-character identifier limit.",
                );
            }
        }
    }

    public function test_default_generation_factory_uses_the_same_user_as_its_material(): void
    {
        $generation = AiGeneration::factory()->create();

        $this->assertNotNull($generation->material);
        $this->assertSame($generation->user_id, $generation->material->user_id);
    }

    public function test_generation_status_defaults_to_queued(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        $generation = AiGeneration::factory()->for($user)->for($material)->create([
            'generation_status' => GenerationStatus::QUEUED,
        ]);

        $this->assertSame(GenerationStatus::QUEUED, $generation->fresh()->generation_status);
        $this->assertSame(AssessmentType::FORMATIVE, $generation->assessment_type);
        $this->assertSame(DifficultyLevel::MEDIUM, $generation->difficulty_level);
        $this->assertSame(QuestionType::MULTIPLE_CHOICE, $generation->question_type);
    }

    public function test_second_usage_row_for_the_same_generation_is_rejected(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create();
        AiUsageLog::factory()->for($generation, 'generation')->create([
            'user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);

        AiUsageLog::factory()->for($generation, 'generation')->create([
            'user_id' => $user->id,
        ]);
    }

    public function test_deleting_a_user_with_generation_history_is_restricted(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        AiGeneration::factory()->for($user)->for($material)->create();

        $this->expectException(QueryException::class);

        $user->delete();
    }

    public function test_user_material_plan_and_generation_relations(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create();
        $usage = AiUsageLog::factory()->for($generation, 'generation')->create([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('code', 'free')->firstOrFail()->plan_id,
        ]);

        $this->assertTrue($user->fresh()->generations->first()->is($generation));
        $this->assertTrue($material->fresh()->generations->first()->is($generation));
        $this->assertTrue($generation->fresh()->usageLog->is($usage));
        $this->assertTrue($usage->fresh()->generation->is($generation));
        $this->assertTrue($user->fresh()->usageLogs->first()->is($usage));
        $this->assertTrue($usage->fresh()->plan->is(Plan::query()->where('code', 'free')->firstOrFail()));
    }

    public function test_rolling_back_phase_four_drops_only_generation_tables(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_31_140002_create_ai_generation_attempts_table.php',
        ])->assertSuccessful();
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_31_140001_add_phase_four_three_columns_to_ai_generations_table.php',
        ])->assertSuccessful();
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_29_100002_create_ai_usage_logs_table.php',
        ])->assertSuccessful();
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_29_100001_create_ai_generations_table.php',
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('ai_usage_logs'));
        $this->assertFalse(Schema::hasTable('ai_generations'));
        $this->assertTrue(Schema::hasTable('subscription_upgrade_requests'));
        $this->assertTrue(Schema::hasTable('plan_offers'));
        $this->assertTrue(Schema::hasTable('subscriptions'));
        $this->assertTrue(Schema::hasTable('plans'));
        $this->assertTrue(Schema::hasTable('materials'));
        $this->assertTrue(Schema::hasTable('users'));
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
     * @return array{name: string, columns: list<string>, unique: bool}
     */
    private function index(string $table, array $columns, bool $unique): array
    {
        $index = collect(Schema::getIndexes($table))
            ->first(fn (array $index): bool => $index['columns'] === $columns && $index['unique'] === $unique);

        $this->assertIsArray($index, 'Expected index on '.$table.' ('.implode(', ', $columns).')');

        return $index;
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
