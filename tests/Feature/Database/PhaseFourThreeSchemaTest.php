<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseFourThreeSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_attempts_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ai_generation_attempts'));
        $this->assertTrue(Schema::hasColumns('ai_generation_attempts', [
            'attempt_id',
            'generation_id',
            'attempt_number',
            'provider',
            'model',
            'purpose',
            'prompt_version',
            'requested_count',
            'accepted_count',
            'status',
            'input_tokens',
            'output_tokens',
            'total_tokens',
            'latency_ms',
            'finish_reason',
            'safe_error_code',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_attempts_omit_raw_payload_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('ai_generation_attempts', 'raw_response'));
        $this->assertFalse(Schema::hasColumn('ai_generation_attempts', 'prompt_body'));
        $this->assertFalse(Schema::hasTable('prompt_versions'));
        $this->assertFalse(Schema::hasColumn('ai_generations', 'prompt_version'));
    }

    public function test_attempt_number_and_generation_are_unique(): void
    {
        $this->assertTrue($this->hasIndex('ai_generation_attempts', ['generation_id', 'attempt_number'], unique: true));

        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();
        $generation = AiGeneration::factory()->for($user)->for($material)->create();

        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 1,
        ]);

        $this->expectException(QueryException::class);

        AiGenerationAttempt::factory()->for($generation, 'generation')->create([
            'attempt_number' => 1,
        ]);
    }

    public function test_generation_factory_defaults_attempt_number_to_zero(): void
    {
        $generation = AiGeneration::factory()->create();

        $this->assertSame(0, $generation->attempt_number);
        $this->assertNull($generation->execution_token);
    }

    public function test_index_and_foreign_key_names_fit_mysql_identifier_limit(): void
    {
        foreach (Schema::getIndexes('ai_generation_attempts') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']));
        }

        foreach (Schema::getForeignKeys('ai_generation_attempts') as $foreignKey) {
            if (! isset($foreignKey['name']) || $foreignKey['name'] === '') {
                continue;
            }

            $this->assertLessThanOrEqual(64, strlen((string) $foreignKey['name']));
        }
    }

    public function test_attempt_factory_persists_prompt_version_and_purpose(): void
    {
        $attempt = AiGenerationAttempt::factory()->create([
            'purpose' => GenerationAttemptPurpose::REPAIR,
            'status' => GenerationAttemptStatus::STARTED,
            'prompt_version' => 'mcq-v2',
        ]);

        $this->assertSame(GenerationAttemptPurpose::REPAIR, $attempt->fresh()->purpose);
        $this->assertSame('mcq-v2', $attempt->fresh()->prompt_version);
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
