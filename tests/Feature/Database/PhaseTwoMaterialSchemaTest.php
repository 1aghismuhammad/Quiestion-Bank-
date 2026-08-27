<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Models\Material;
use App\Models\MaterialTopic;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseTwoMaterialSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('materials'));
        $this->assertTrue(Schema::hasTable('material_topics'));
    }

    public function test_materials_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('materials', [
            'material_id',
            'user_id',
            'title',
            'source_type',
            'file_name',
            'file_path',
            'file_size',
            'file_hash',
            'mime_type',
            'content',
            'extraction_status',
            'status',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_material_topics_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('material_topics', [
            'topic_id',
            'material_id',
            'topic_name',
            'focus_area',
            'chapter',
            'sub_chapter',
            'sort_order',
            'page_start',
            'page_end',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_materials_user_foreign_key_restricts_delete(): void
    {
        $foreignKey = $this->foreignKey('materials', 'user_id');

        $this->assertSame('users', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertContains($foreignKey['on_delete'], ['restrict', 'no action']);
    }

    public function test_material_topics_material_foreign_key_cascades_delete(): void
    {
        $foreignKey = $this->foreignKey('material_topics', 'material_id');

        $this->assertSame('materials', $foreignKey['foreign_table']);
        $this->assertSame(['material_id'], $foreignKey['foreign_columns']);
        $this->assertSame('cascade', $foreignKey['on_delete']);
    }

    public function test_materials_have_composite_indexes_and_unique_hash(): void
    {
        $this->assertTrue($this->hasIndex('materials', ['user_id', 'status'], unique: false));
        $this->assertTrue($this->hasIndex('materials', ['user_id', 'extraction_status'], unique: false));
        $this->assertTrue($this->hasIndex('materials', ['user_id', 'file_hash'], unique: true));
    }

    public function test_material_topics_have_composite_unique_index(): void
    {
        $this->assertTrue($this->hasIndex(
            'material_topics',
            ['material_id', 'sort_order'],
            unique: false,
        ));
        $this->assertTrue($this->hasIndex(
            'material_topics',
            ['material_id', 'chapter', 'sub_chapter', 'topic_name'],
            unique: true,
        ));

        $unique = $this->index(
            'material_topics',
            ['material_id', 'chapter', 'sub_chapter', 'topic_name'],
            unique: true,
        );

        $this->assertSame('material_topics_path_unique', $unique['name']);
        $this->assertLessThanOrEqual(64, strlen($unique['name']));
        $this->assertGreaterThan(
            64,
            strlen('material_topics_material_id_chapter_sub_chapter_topic_name_unique'),
        );
    }

    public function test_phase_two_index_and_foreign_key_names_fit_mysql_identifier_limit(): void
    {
        foreach (['materials', 'material_topics'] as $table) {
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

    public function test_materials_enum_defaults_are_pending_and_draft(): void
    {
        $user = User::factory()->create();

        $materialId = DB::table('materials')->insertGetId([
            'user_id' => $user->id,
            'title' => 'Schema default material',
            'source_type' => SourceType::TEXT->value,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'material_id');

        $material = Material::query()->findOrFail($materialId);

        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
    }

    public function test_material_topics_default_chapter_and_sub_chapter_to_empty_string(): void
    {
        $material = Material::factory()->create();

        $topicId = DB::table('material_topics')->insertGetId([
            'material_id' => $material->material_id,
            'topic_name' => 'Introduction',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'topic_id');

        $topic = MaterialTopic::query()->findOrFail($topicId);

        $this->assertSame('', $topic->chapter);
        $this->assertSame('', $topic->sub_chapter);
        $this->assertSame(0, $topic->sort_order);
    }

    public function test_user_has_many_materials_and_material_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for($user)->create();

        $this->assertTrue($user->fresh()->materials->first()->is($material));
        $this->assertTrue($material->fresh()->user->is($user));
    }

    public function test_material_has_many_topics_and_topic_belongs_to_material(): void
    {
        $material = Material::factory()->create();
        $second = MaterialTopic::factory()->for($material, 'material')->create(['sort_order' => 2]);
        $first = MaterialTopic::factory()->for($material, 'material')->create(['sort_order' => 1]);

        $topics = $material->fresh()->topics;

        $this->assertTrue($topics->first()->is($first));
        $this->assertTrue($topics->last()->is($second));
        $this->assertTrue($first->fresh()->material->is($material));
    }

    public function test_soft_deleting_a_material_hides_it_without_removing_topics(): void
    {
        $material = Material::factory()->create();
        $topic = MaterialTopic::factory()->for($material, 'material')->create();

        $material->delete();

        $this->assertSoftDeleted('materials', ['material_id' => $material->material_id]);
        $this->assertDatabaseHas('material_topics', ['topic_id' => $topic->topic_id]);
        $this->assertNull(Material::query()->find($material->material_id));
        $this->assertTrue(Material::withTrashed()->findOrFail($material->material_id)->trashed());
    }

    public function test_duplicate_file_hash_for_the_same_user_is_rejected(): void
    {
        $user = User::factory()->create();
        $hash = hash('sha256', 'duplicate-material');

        Material::factory()->upload()->for($user)->create(['file_hash' => $hash]);

        $this->expectException(QueryException::class);

        Material::factory()->upload()->for($user)->create(['file_hash' => $hash]);
    }

    public function test_null_file_hashes_are_allowed_for_the_same_user(): void
    {
        $user = User::factory()->create();

        Material::factory()->text()->for($user)->create();
        Material::factory()->text()->for($user)->create();

        $this->assertSame(2, $user->materials()->count());
    }

    public function test_duplicate_topic_path_on_the_same_material_is_rejected(): void
    {
        $material = Material::factory()->create();

        MaterialTopic::factory()->for($material, 'material')->create([
            'topic_name' => 'Photosynthesis',
            'chapter' => '1',
            'sub_chapter' => '2',
        ]);

        $this->expectException(QueryException::class);

        MaterialTopic::factory()->for($material, 'material')->create([
            'topic_name' => 'Photosynthesis',
            'chapter' => '1',
            'sub_chapter' => '2',
        ]);
    }

    public function test_rolling_back_phase_two_one_drops_only_material_tables(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('material_topics'));
        $this->assertFalse(Schema::hasTable('materials'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('role_user'));
        $this->assertTrue(Schema::hasTable('whatsapp_contacts'));
    }

    /**
     * @return array{foreign_table: string, foreign_columns: list<string>, on_delete: string}
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
