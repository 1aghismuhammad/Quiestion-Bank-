<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaterialProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('material_profile_versions'));
        $this->assertTrue(Schema::hasTable('material_profile_chunks'));
        $this->assertTrue(Schema::hasTable('material_profile_steps'));
        $this->assertTrue(Schema::hasTable('material_profile_elements'));
        $this->assertTrue(Schema::hasTable('material_profile_attempts'));

        $this->assertTrue(Schema::hasColumns('material_profile_versions', [
            'profile_version_id',
            'material_id',
            'user_id',
            'version',
            'status',
            'workflow_token',
            'queued_at',
            'material_content_hash',
            'extractor_implementation',
        ]));
        $this->assertTrue(Schema::hasColumns('material_profile_chunks', [
            'profile_chunk_id',
            'profile_version_id',
            'chunk_index',
            'char_start',
            'char_end',
            'core_text_hash',
            'required',
        ]));
        $this->assertFalse(Schema::hasColumn('material_profile_chunks', 'status'));
        $this->assertFalse(Schema::hasColumn('material_profile_versions', 'reduce_status'));
        $this->assertTrue(Schema::hasColumns('material_profile_steps', [
            'profile_step_id',
            'purpose',
            'step_index',
            'profile_chunk_id',
            'workflow_token',
            'step_execution_token',
            'lease_expires_at',
        ]));
        $this->assertFalse(Schema::hasColumn('material_profile_attempts', 'raw_prompt'));
        $this->assertFalse(Schema::hasColumn('material_profile_attempts', 'raw_response'));
    }

    public function test_unique_and_lookup_indexes_are_named_within_mysql_limit(): void
    {
        foreach (Schema::getIndexes('material_profile_versions') as $index) {
            $this->assertLessThanOrEqual(64, strlen((string) $index['name']));
        }

        foreach (Schema::getIndexes('material_profile_steps') as $index) {
            $this->assertLessThanOrEqual(64, strlen((string) $index['name']));
        }

        $this->assertTrue($this->hasIndex('material_profile_versions', ['material_id', 'version'], true));
        $this->assertTrue($this->hasIndex('material_profile_steps', ['profile_version_id', 'purpose', 'step_index'], true));
        $this->assertTrue($this->hasIndex('material_profile_steps', ['profile_chunk_id'], true));
        $this->assertTrue($this->hasIndex('material_profile_attempts', ['profile_step_id', 'attempt_number'], true));
        $this->assertTrue($this->hasIndex('material_profile_chunks', ['profile_version_id', 'chunk_index'], true));

        foreach ([
            'material_profile_versions',
            'material_profile_chunks',
            'material_profile_steps',
            'material_profile_elements',
            'material_profile_attempts',
        ] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen((string) $index['name']));
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $this->assertLessThanOrEqual(64, strlen((string) ($foreignKey['name'] ?? '')));
            }
        }
    }

    public function test_foreign_keys_restrict_on_parent_tables(): void
    {
        $versionMaterial = $this->foreignKey('material_profile_versions', 'material_id');
        $this->assertSame('materials', $versionMaterial['foreign_table']);

        $stepVersion = $this->foreignKey('material_profile_steps', 'profile_version_id');
        $this->assertSame('material_profile_versions', $stepVersion['foreign_table']);
    }

    public function test_duplicate_material_version_number_is_rejected(): void
    {
        $version = MaterialProfileVersion::factory()->create();

        $this->expectException(QueryException::class);

        MaterialProfileVersion::factory()->create([
            'material_id' => $version->material_id,
            'user_id' => $version->user_id,
            'version' => $version->version,
        ]);
    }

    public function test_duplicate_version_purpose_and_step_index_is_rejected(): void
    {
        $version = MaterialProfileVersion::factory()->create();

        MaterialProfileStep::factory()->reduce()->create([
            'profile_version_id' => $version->profile_version_id,
            'workflow_token' => $version->workflow_token,
        ]);

        $this->expectException(QueryException::class);

        MaterialProfileStep::factory()->reduce()->create([
            'profile_version_id' => $version->profile_version_id,
            'workflow_token' => $version->workflow_token,
        ]);
    }

    public function test_duplicate_attempt_number_on_the_same_step_is_rejected(): void
    {
        $step = MaterialProfileStep::factory()->reduce()->create();

        MaterialProfileAttempt::factory()->create([
            'profile_step_id' => $step->profile_step_id,
            'attempt_number' => 1,
        ]);

        $this->expectException(QueryException::class);

        MaterialProfileAttempt::factory()->create([
            'profile_step_id' => $step->profile_step_id,
            'attempt_number' => 1,
        ]);
    }

    public function test_two_map_steps_cannot_share_a_chunk(): void
    {
        $version = MaterialProfileVersion::factory()->create();
        $chunk = MaterialProfileChunk::factory()->create([
            'profile_version_id' => $version->profile_version_id,
        ]);

        MaterialProfileStep::factory()->map()->create([
            'profile_version_id' => $version->profile_version_id,
            'profile_chunk_id' => $chunk->profile_chunk_id,
            'workflow_token' => $version->workflow_token,
            'step_index' => 0,
        ]);

        $this->expectException(QueryException::class);

        MaterialProfileStep::factory()->map()->create([
            'profile_version_id' => $version->profile_version_id,
            'profile_chunk_id' => $chunk->profile_chunk_id,
            'workflow_token' => $version->workflow_token,
            'step_index' => 1,
        ]);
    }

    public function test_reduce_null_chunk_does_not_conflict_with_unique_chunk_id(): void
    {
        $first = MaterialProfileStep::factory()->reduce()->create();
        $second = MaterialProfileStep::factory()->reduce()->create();

        $this->assertNull($first->profile_chunk_id);
        $this->assertNull($second->profile_chunk_id);
        $this->assertSame(MaterialProfileStepPurpose::REDUCE, $first->purpose);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $first->status);
    }

    /**
     * @return array{foreign_table: string, foreign_columns: list<string>}
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
