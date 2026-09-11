<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
